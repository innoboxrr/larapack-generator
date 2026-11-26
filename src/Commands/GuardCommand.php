<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * El hook que impide a un agente perder la guía a mitad de una sesión.
 *
 * Claude Code le pasa por la entrada estándar el JSON de la herramienta que
 * acaba de ejecutarse. Si el archivo tocado es arquitectura y el Verifier
 * encuentra errores en él, el comando sale con código 2: Claude Code lo trata
 * como bloqueante y devuelve la salida de error al modelo, que la lee en el
 * momento en que todavía tiene el archivo delante. Con `--stop` hace lo mismo
 * al terminar el turno, sobre el proyecto entero, pero sólo retiene por lo que
 * tocó la sesión.
 *
 * Todo lo demás —un archivo que no es arquitectura, una entrada vacía o rota,
 * lo de vendor/, un proyecto sin manifiesto fuera de sus controladores— sale
 * con 0 y sin decir nada: un hook que estorba en cada edición acaba
 * desactivado.
 *
 * Con `--pre` (o el evento PreToolUse) es el hook de antes de la herramienta:
 * sólo mira si va a tocar la aprobación de las excepciones —editar
 * `.larapack/hand-rolled.lock` o correr `larapack:hand-rolled approve` o
 * `revoke`— y, si es así, la impide. Después de la herramienta (PostToolUse)
 * también lo bloquea, pero ya está hecho: sólo puede pedir que se deshaga.
 */
class GuardCommand extends Command
{
    /**
     * El código que Claude Code entiende como «bloquea y dile al modelo por
     * qué». Cualquier otro distinto de 0 sólo se le enseña al usuario.
     */
    public const BLOCK = 2;

    protected function configure(): void
    {
        $this->setName('larapack:guard')
            ->setDescription('Hook para agentes: comprueba contra el contrato el archivo que se acaba de editar')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Hook de fin de turno: verifica el proyecto entero')
            ->addOption('pre', null, InputOption::VALUE_NONE, 'Hook de antes de la herramienta: impide tocar la aprobación de las excepciones #[HandRolled]')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Raíz del proyecto (por defecto, la del archivo tocado)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('root');

        if (is_string($root) && $root !== '') {
            $resolved = realpath($root);

            // Ignorarlo en silencio dejaría a un hook mal configurado
            // verificando otro proyecto, o ninguno, sin que nadie lo note. Con
            // 1 Claude Code se lo enseña al usuario sin bloquear al agente.
            if ($resolved === false || ! is_dir($resolved)) {
                $this->stderr($output)->writeln(
                    "larapack:guard: --root={$root} no existe o no es un directorio; no se comprobó nada. Corrige el comando del hook.",
                    OutputInterface::OUTPUT_RAW
                );

                return Command::FAILURE;
            }

            $root = str_replace('\\', '/', $resolved);
        } else {
            $root = null;
        }

        $payload = $this->payload($input);

        if ($payload === null) {
            return Command::SUCCESS;
        }

        try {
            $stop = $input->getOption('stop')
                || in_array($payload['hook_event_name'] ?? null, ['Stop', 'SubagentStop'], true);

            if ($stop) {
                return $this->onStop($payload, $root, $output);
            }

            return $input->getOption('pre') || ($payload['hook_event_name'] ?? null) === 'PreToolUse'
                ? $this->onPre($payload, $output)
                : $this->onEdit($payload, $root, $output);
        } catch (Throwable $e) {
            // Un fallo de la guardia no debe bloquear al agente: con 1 Claude
            // Code se lo enseña al usuario y sigue.
            $this->stderr($output)->writeln('larapack:guard falló: '.$e->getMessage(), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }

    /**
     * Antes de la herramienta: impide que un agente apruebe o retire una
     * excepción #[HandRolled]. Nada más: lo demás se comprueba sobre lo
     * escrito, después.
     *
     * Con 2 Claude Code no ejecuta la herramienta y le enseña al modelo el
     * porqué.
     *
     * @param  array<string, mixed>  $payload
     */
    private function onPre(array $payload, OutputInterface $output): int
    {
        $message = $this->lockMessage($payload, false);

        if ($message === null) {
            return Command::SUCCESS;
        }

        $this->stderr($output)->writeln($message, OutputInterface::OUTPUT_RAW);

        return self::BLOCK;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function lockMessage(array $payload, bool $done): ?string
    {
        $locks = Guard::touchedLocks($payload);
        $command = Guard::approvesHandRolled($payload);

        return $locks === [] && ! $command ? null : Guard::lockMessage($locks, $command, $done);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onEdit(array $payload, ?string $root, OutputInterface $output): int
    {
        $locked = $this->lockMessage($payload, true);
        $messages = $locked === null ? [] : [$locked];
        $reminders = [];

        foreach (Guard::touchedFiles($payload) as $file) {
            if (Guard::isThirdParty($file)) {
                continue;
            }

            $project = $root ?? Guard::projectOf($file);

            if ($project === null || ($relative = Guard::relative($project, $file)) === null) {
                continue;
            }

            // Para el hook de fin de turno: es de esta sesión aunque luego se
            // confirme, y aunque hoy no sea arquitectura.
            Guard::remember($payload, $file);

            $manifest = new Manifest($project);
            $managed = is_file($manifest->path());

            // Sin manifiesto sólo queda lo que `verify` comprueba igual sin
            // él: la forma de los controladores (Guard::isShapeFile()).
            if (! $managed && ! Guard::isShapeFile($relative)) {
                continue;
            }

            $manifest = $manifest->snapshot();

            if (! Guard::watches($relative, $manifest)) {
                continue;
            }

            $errors = Guard::errorsFor(Guard::findings($project, $managed ? [] : ControllerShape::CHECKS), $relative);

            if ($errors !== []) {
                $messages[] = Guard::message('LaraPack: '.Guard::inline($relative).' se sale del contrato.', $errors, $project);

                continue;
            }

            $reminder = Guard::reminderFor($relative, $file, $manifest);

            if ($reminder !== null) {
                $reminders[] = $reminder;
            }
        }

        if ($messages !== []) {
            $this->stderr($output)->writeln(implode("\n\n", $messages), OutputInterface::OUTPUT_RAW);

            return self::BLOCK;
        }

        if ($reminders !== []) {
            // Con 0, Claude Code lee la salida estándar como JSON: el texto
            // llega al modelo junto al resultado de la herramienta, sin
            // bloquear nada.
            $this->json($output, [
                'hookSpecificOutput' => [
                    'hookEventName' => 'PostToolUse',
                    'additionalContext' => implode("\n\n", $reminders),
                ],
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Al terminar el turno, el proyecto entero: lo mismo que
     * `larapack:verify --format=json`, pero con el código que Claude Code
     * entiende como «todavía no has terminado».
     *
     * Sólo retienen el turno los errores de lo que tocó esta sesión
     * (Guard::sessionFiles()). Los demás se le enseñan al usuario sin
     * bloquear: con varias consolas sobre el mismo proyecto, retener a una
     * por lo que otra dejó a medias la mandaría a «arreglar» trabajo ajeno, y
     * un proyecto con errores antiguos retendría cada turno de cada sesión.
     *
     * @param  array<string, mixed>  $payload
     */
    private function onStop(array $payload, ?string $root, OutputInterface $output): int
    {
        // Si el turno ya sigue por culpa de este hook, se deja terminar: un
        // error que el agente no sabe arreglar no puede retenerlo para siempre.
        if (($payload['stop_hook_active'] ?? false) === true) {
            return Command::SUCCESS;
        }

        $project = $root ?? Guard::projectOf(Guard::workingDirectory($payload));

        if ($project === null) {
            return Command::SUCCESS;
        }

        // Sin manifiesto, como en la edición, sólo la forma de los
        // controladores: es lo único que verify mira sin él.
        $managed = is_file((new Manifest($project))->path());
        $errors = Guard::errorsFor(Guard::findings($project, $managed ? [] : ControllerShape::CHECKS));

        if ($errors === []) {
            return Command::SUCCESS;
        }

        $sessionFiles = Guard::sessionFiles($payload, $project);
        [$blocking, $others] = Guard::partition($errors, $sessionFiles);

        if ($blocking === []) {
            // Con 0 y un JSON en la salida estándar, systemMessage es un aviso
            // para el usuario; el turno termina.
            $this->json($output, ['systemMessage' => Guard::message(sprintf(
                'LaraPack: larapack:verify encuentra %s en archivos que esta sesión no tocó; no retienen el turno.',
                $this->errors(count($others))
            ), $others, $project)]);

            return Command::SUCCESS;
        }

        // Sin forma de saber qué tocó la sesión, retiene todo, y no se dice
        // que sea suyo.
        $message = Guard::message(sprintf(
            'LaraPack: larapack:verify encuentra %s%s; la tarea no ha terminado.',
            $this->errors(count($blocking)),
            $sessionFiles === null ? '' : ' en lo que tocó esta sesión'
        ), $blocking, $project);

        if ($others !== []) {
            $message .= "\n".sprintf(
                'Además hay %s en archivos que esta sesión no tocó: no son tuyos ni te retienen; no los corrijas sin que te lo pidan.',
                $this->errors(count($others))
            );
        }

        $this->stderr($output)->writeln($message, OutputInterface::OUTPUT_RAW);

        return self::BLOCK;
    }

    private function errors(int $count): string
    {
        return $count === 1 ? 'un error' : "{$count} errores";
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function json(OutputInterface $output, array $document): void
    {
        $output->writeln((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
    }

    /**
     * El JSON del hook, o null si no hay nada que leer o no es un objeto.
     *
     * Sin entrada redirigida no se lee: ejecutado a mano en una terminal, el
     * comando se quedaría esperando a que alguien escribiera.
     *
     * @return array<string, mixed>|null
     */
    private function payload(InputInterface $input): ?array
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        if ($stream === null) {
            if (! defined('STDIN') || stream_isatty(STDIN)) {
                return null;
            }

            $stream = STDIN;
        }

        $raw = stream_get_contents($stream);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function stderr(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
