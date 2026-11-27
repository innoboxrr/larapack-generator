<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Support\AgentAdapters;
use Innoboxrr\LarapackGenerator\Support\AgentIntegrations;
use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\GitHooks;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\GuardDoctor;
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
 * `revoke`— o a saltarse el hook de git (`--no-verify`, cambiar
 * `core.hooksPath`) y, si es así, lo impide. Después de la herramienta
 * (PostToolUse) el candado también se bloquea, pero ya está hecho: sólo puede
 * pedir que se deshaga.
 *
 * Con `--agent` habla el idioma de otro agente —Cursor, Codex, Gemini CLI,
 * Copilot, Windsurf— (AgentAdapters): la decisión es la misma; cambia cómo
 * llega la entrada y cómo se contesta. Sin `--agent`, lo deduce de la entrada.
 *
 * Con `--git` es el hook de git (GitHooks), la capa que no depende de ningún
 * agente, y con `--doctor` dice qué capas tiene el proyecto y cuáles le faltan
 * (GuardDoctor).
 *
 * @phpstan-import-type Verdict from AgentAdapters
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
            ->setDescription('Hook para agentes y para git: comprueba contra el contrato lo que se acaba de editar o se va a comitear')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Hook de fin de turno: verifica el proyecto entero')
            ->addOption('pre', null, InputOption::VALUE_NONE, 'Hook de antes de la herramienta: impide tocar la aprobación de las excepciones #[HandRolled] y saltarse el hook de git')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Raíz del proyecto (por defecto, la del archivo tocado)')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'El agente que llama al hook: '.implode(', ', AgentAdapters::AGENTS).' (por defecto, se deduce de la entrada)')
            ->addOption('git', null, InputOption::VALUE_REQUIRED, 'Hook de git ('.implode(' o ', GitHooks::HOOKS).'): comprueba lo que se va a comitear o a empujar')
            ->addOption('doctor', null, InputOption::VALUE_NONE, 'Dice qué capas de la guardia tiene el proyecto y cuáles faltan, con la orden para instalarlas')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Con --doctor: txt o json', 'txt');
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

        if ($input->getOption('doctor')) {
            return $this->doctor($root ?? str_replace('\\', '/', (string) getcwd()), (string) $input->getOption('format'), $output);
        }

        $git = $input->getOption('git');

        if (is_string($git) && $git !== '') {
            return $this->git($git, $input, $output);
        }

        $agent = $input->getOption('agent');

        if ($agent !== null && ! in_array($agent, AgentAdapters::AGENTS, true)) {
            $this->stderr($output)->writeln(
                'larapack:guard: --agent='.Guard::inline((string) $agent).' no es un agente conocido ('.implode(', ', AgentAdapters::AGENTS).'); no se comprobó nada. Corrige el comando del hook.',
                OutputInterface::OUTPUT_RAW
            );

            return Command::FAILURE;
        }

        $raw = $this->payload($input);

        if ($raw === null) {
            return Command::SUCCESS;
        }

        $detected = AgentAdapters::detect($raw);
        $agent = is_string($agent) ? $agent : $detected;
        $payload = AgentAdapters::normalise($agent, $raw);
        $event = $this->event($input, $payload);

        // Cursor ejecuta también los hooks de .claude/settings.json, con su
        // entrada. Si el proyecto ya tiene los de Cursor, ésos contestan y
        // éste calla: la misma comprobación dos veces sólo duplica la espera
        // y el mensaje.
        if ($input->getOption('agent') === null && $detected === AgentAdapters::CURSOR
            && AgentIntegrations::hasNative($this->projectFor($payload, $root), AgentAdapters::CURSOR)) {
            return Command::SUCCESS;
        }

        try {
            $verdict = match ($event) {
                'stop' => $this->onStop($payload, $root),
                'pre' => $this->onPre($payload),
                default => $this->onEdit($payload, $root),
            };
        } catch (Throwable $e) {
            // Un fallo de la guardia no debe bloquear al agente: con 1 Claude
            // Code se lo enseña al usuario y sigue.
            $verdict = ['kind' => 'failure', 'message' => self::failure($e)];
        }

        $reply = AgentAdapters::reply($agent, $event, $verdict);

        if ($reply['stdout'] !== '') {
            $output->writeln($reply['stdout'], OutputInterface::OUTPUT_RAW);
        }

        if ($reply['stderr'] !== '') {
            $this->stderr($output)->writeln($reply['stderr'], OutputInterface::OUTPUT_RAW);
        }

        return $reply['code'];
    }

    /**
     * Qué hook es: la opción del comando manda; si no hay, el evento de la
     * entrada. Sin ninguno, el de después de editar, como siempre.
     *
     * @param  array<string, mixed>  $payload
     * @return 'pre'|'edit'|'stop'
     */
    private function event(InputInterface $input, array $payload): string
    {
        $name = $payload['hook_event_name'] ?? null;

        if ($input->getOption('stop') || in_array($name, ['Stop', 'SubagentStop'], true)) {
            return 'stop';
        }

        return $input->getOption('pre') || $name === 'PreToolUse' ? 'pre' : 'edit';
    }

    /**
     * La línea que se enseña cuando la guardia falla. El mensaje de la
     * excepción puede citar texto del proyecto —una ruta, un valor del
     * laraimport—, y va a un sitio que el modelo lee: en una sola línea, como
     * todo lo que la guardia cita del proyecto (Guard::inline()).
     *
     * @internal
     */
    public static function failure(Throwable $e): string
    {
        return 'larapack:guard falló: '.Guard::inline($e->getMessage());
    }

    /**
     * El proyecto de la sesión, para lo que no depende de un archivo.
     *
     * @param  array<string, mixed>  $payload
     */
    private function projectFor(array $payload, ?string $root): ?string
    {
        return $root ?? Guard::projectOf(Guard::workingDirectory($payload));
    }

    /**
     * El hook de git: 0 deja seguir, 2 bloquea y 3 pide que lo confirme una
     * persona desde una terminal (lo pregunta el script del hook, no PHP). Si
     * la guardia falla sale con 1, y el script deja seguir avisando: la CI lo
     * comprobará.
     */
    private function git(string $hook, InputInterface $input, OutputInterface $output): int
    {
        if (! in_array($hook, GitHooks::HOOKS, true)) {
            $this->stderr($output)->writeln(
                'larapack:guard: --git='.Guard::inline($hook).' no es un hook conocido ('.implode(', ', GitHooks::HOOKS).').',
                OutputInterface::OUTPUT_RAW
            );

            return Command::FAILURE;
        }

        try {
            $result = GitHooks::check($hook, str_replace('\\', '/', (string) getcwd()), $hook === 'pre-push' ? $this->rawInput($input) : '');
        } catch (Throwable $e) {
            $this->stderr($output)->writeln(self::failure($e), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        if ($result['message'] !== '') {
            $this->stderr($output)->writeln($result['message'], OutputInterface::OUTPUT_RAW);
        }

        return $result['code'];
    }

    private function doctor(string $root, string $format, OutputInterface $output): int
    {
        $diagnosis = GuardDoctor::diagnose($root);

        if ($format === 'json') {
            $this->json($output, $diagnosis);
        } else {
            $output->writeln(GuardDoctor::render($diagnosis), OutputInterface::OUTPUT_RAW);
        }

        return $diagnosis['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Antes de la herramienta: impide que un agente apruebe o retire una
     * excepción #[HandRolled] y que se salte el hook de git. Nada más: lo
     * demás se comprueba sobre lo escrito, después.
     *
     * Con 2 Claude Code no ejecuta la herramienta y le enseña al modelo el
     * porqué.
     *
     * @param  array<string, mixed>  $payload
     * @return Verdict
     */
    private function onPre(array $payload): array
    {
        $bypasses = Guard::bypassesGitHooks($payload);
        $messages = array_values(array_filter([
            $this->lockMessage($payload, false),
            $bypasses === [] ? null : Guard::bypassMessage($bypasses),
        ]));

        return $messages === []
            ? ['kind' => 'pass', 'message' => '']
            : ['kind' => 'block', 'message' => implode("\n\n", $messages)];
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
     * @return Verdict
     */
    private function onEdit(array $payload, ?string $root): array
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
            return ['kind' => 'block', 'message' => implode("\n\n", $messages)];
        }

        // Un recordatorio llega al modelo junto al resultado de la
        // herramienta, sin bloquear nada (en Claude Code, el JSON con
        // additionalContext).
        return $reminders === []
            ? ['kind' => 'pass', 'message' => '']
            : ['kind' => 'context', 'message' => implode("\n\n", $reminders)];
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
     * @return Verdict
     */
    private function onStop(array $payload, ?string $root): array
    {
        $pass = ['kind' => 'pass', 'message' => ''];

        // Si el turno ya sigue por culpa de este hook, se deja terminar: un
        // error que el agente no sabe arreglar no puede retenerlo para siempre.
        if (($payload['stop_hook_active'] ?? false) === true) {
            return $pass;
        }

        $project = $this->projectFor($payload, $root);

        if ($project === null) {
            return $pass;
        }

        // Sin manifiesto, como en la edición, sólo la forma de los
        // controladores: es lo único que verify mira sin él.
        $managed = is_file((new Manifest($project))->path());
        $errors = Guard::errorsFor(Guard::findings($project, $managed ? [] : ControllerShape::CHECKS));

        if ($errors === []) {
            return $pass;
        }

        $sessionFiles = Guard::sessionFiles($payload, $project);
        [$blocking, $others] = Guard::partition($errors, $sessionFiles);

        if ($blocking === []) {
            // Un aviso para el usuario (en Claude Code, systemMessage); el
            // turno termina.
            return ['kind' => 'notice', 'message' => Guard::message(sprintf(
                'LaraPack: larapack:verify encuentra %s en archivos que esta sesión no tocó; no retienen el turno.',
                $this->errors(count($others))
            ), $others, $project)];
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

        return ['kind' => 'block', 'message' => $message];
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
     * @return array<string, mixed>|null
     */
    private function payload(InputInterface $input): ?array
    {
        $raw = $this->rawInput($input);

        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Lo que llega por la entrada estándar, o '' si no hay nada redirigido.
     *
     * Sin entrada redirigida no se lee: ejecutado a mano en una terminal, el
     * comando se quedaría esperando a que alguien escribiera.
     */
    private function rawInput(InputInterface $input): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        if ($stream === null) {
            if (! defined('STDIN') || stream_isatty(STDIN)) {
                return '';
            }

            $stream = STDIN;
        }

        $raw = stream_get_contents($stream);

        return is_string($raw) ? $raw : '';
    }

    private function stderr(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
