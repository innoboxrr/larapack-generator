<?php

namespace Innoboxrr\LarapackGenerator\Commands;

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
 * al terminar el turno, sobre el proyecto entero.
 *
 * Todo lo demás —un archivo que no es arquitectura, una entrada vacía o rota,
 * un proyecto sin LaraPack— sale con 0 y sin decir nada: un hook que estorba
 * en cada edición acaba desactivado.
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
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Raíz del proyecto (por defecto, la del archivo tocado)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->payload($input);

        if ($payload === null) {
            return Command::SUCCESS;
        }

        $root = $input->getOption('root');
        $root = is_string($root) && $root !== '' ? (realpath($root) ?: null) : null;

        try {
            $stop = $input->getOption('stop')
                || in_array($payload['hook_event_name'] ?? null, ['Stop', 'SubagentStop'], true);

            return $stop
                ? $this->onStop($payload, $root, $output)
                : $this->onEdit($payload, $root, $output);
        } catch (Throwable $e) {
            // Un fallo de la guardia no debe bloquear al agente: con 1 Claude
            // Code se lo enseña al usuario y sigue.
            $this->stderr($output)->writeln('larapack:guard falló: '.$e->getMessage(), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onEdit(array $payload, ?string $root, OutputInterface $output): int
    {
        $messages = [];
        $reminders = [];

        foreach (Guard::touchedFiles($payload) as $file) {
            $project = $root ?? Guard::projectOf($file);

            if ($project === null || ($relative = Guard::relative($project, $file)) === null) {
                continue;
            }

            $manifest = (new Manifest($project))->snapshot();

            if (! Guard::watches($relative, $manifest)) {
                continue;
            }

            $errors = Guard::errorsFor(Guard::findings($project), $relative);

            if ($errors !== []) {
                $messages[] = Guard::message("LaraPack: {$relative} se sale del contrato.", $errors, $project);

                continue;
            }

            if (Guard::isHandWrittenController($relative, $manifest)) {
                $reminders[] = Guard::controllerReminder($relative);
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
            $output->writeln((string) json_encode([
                'hookSpecificOutput' => [
                    'hookEventName' => 'PostToolUse',
                    'additionalContext' => implode("\n\n", $reminders),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    /**
     * Al terminar el turno, el proyecto entero: lo mismo que
     * `larapack:verify --format=json`, pero con el código que Claude Code
     * entiende como «todavía no has terminado».
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

        if ($project === null || ! is_file((new Manifest($project))->path())) {
            return Command::SUCCESS;
        }

        $errors = Guard::errorsFor(Guard::findings($project));

        if ($errors === []) {
            return Command::SUCCESS;
        }

        $this->stderr($output)->writeln(
            Guard::message(sprintf(
                'LaraPack: larapack:verify encuentra %s; la tarea no ha terminado.',
                count($errors) === 1 ? 'un error' : count($errors).' errores'
            ), $errors, $project),
            OutputInterface::OUTPUT_RAW
        );

        return self::BLOCK;
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
