<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;

/**
 * La guardia como hook de git: la capa que no depende de ningún agente.
 *
 * Los hooks de Claude Code sólo los ejecuta Claude Code, y cada agente que
 * tiene hooks propios los tiene a su manera (AgentAdapters). Un commit, en
 * cambio, lo hace git, y git ejecuta `pre-commit` y `pre-push` sea quien sea
 * quien comitea: Cursor, Codex, Copilot, Gemini, Windsurf, Aider o una persona.
 * Los scripts viven versionados en `.githooks/` y git los usa con
 * `core.hooksPath`, que es configuración local de cada clon: por eso
 * `larapack:skill --hooks` la pone y `larapack:guard --doctor` avisa si falta.
 *
 * Qué comprueba, y por qué así:
 *
 * - **Sólo lo que se va a comitear (o a empujar).** De lo preparado, lo que es
 *   arquitectura (Guard::watches()), con las comprobaciones de `verify`, y de
 *   sus hallazgos sólo los de esos archivos: un commit no se frena por lo que
 *   otro dejó a medias. Sin nada de eso, ni siquiera corre el Verifier.
 * - **Lo que la CI rechazaría.** Los errores de esos archivos, y además los
 *   avisos de una excepción #[HandRolled] sin aprobar (`hand-rolled`,
 *   `hand-rolled-stale`), que `verify --strict` cuenta como fallos.
 * - **Sobre la copia de trabajo, y por eso exige que coincida con lo
 *   preparado.** El Verifier lee el disco. Un archivo vigilado preparado a
 *   medias (`git add -p`) no se puede comprobar tal como se comitea, y no se
 *   da por bueno: se pide prepararlo entero.
 * - **El candado lo confirma una persona.** Si el commit cambia
 *   `.larapack/hand-rolled.lock`, el script pregunta en la terminal
 *   (`/dev/tty`). Un agente no tiene terminal en la que contestar, así que
 *   el commit no sigue. No es infranqueable —un agente con una terminal
 *   interactiva podría escribir la respuesta—: la barrera es la revisión del
 *   dueño (CODEOWNERS) en la rama protegida.
 *
 * Un hook de git se salta con `--no-verify` o quitándolo. Por eso la guardia
 * de los agentes que tienen hooks impide esas órdenes (Guard::gitBypass()), y
 * la CI comprueba lo mismo: lo que llega a la rama protegida pasa por
 * `verify --strict` aunque aquí nadie lo haya comprobado.
 */
final class GitHooks
{
    public const DIRECTORY = '.githooks';

    public const HOOKS = ['pre-commit', 'pre-push'];

    /**
     * Cómo se reconoce un script de LaraPack: llama a la guardia en modo git.
     */
    public const SIGNATURE = 'larapack:guard --git=';

    /**
     * El código con el que la guardia pide la confirmación de una persona.
     */
    public const CONFIRM = 3;

    /**
     * El código con el que la guardia frena: el mismo que en los hooks de
     * los agentes. El script lo convierte en el 1 que git espera.
     */
    public const BLOCK = 2;

    /**
     * Lo que la persona escribe para confirmar.
     */
    public const PHRASE = 'apruebo';

    /**
     * Las comprobaciones cuyos avisos también frenan: `verify --strict` los
     * cuenta como fallos, y son justo una excepción sin aprobar.
     */
    private const STRICT_WARNINGS = [ControllerShape::HAND_ROLLED, ControllerShape::HAND_ROLLED_STALE];

    /**
     * El candado de las excepciones, en cualquier proyecto del repositorio y
     * sin distinguir mayúsculas (en Windows y macOS es el mismo archivo).
     */
    private const LOCK = '#(^|/)\.larapack/hand-rolled\.lock$#i';

    private const ZERO = '/^0+$/';

    /**
     * El script de un hook.
     *
     * Es POSIX sh: git lo ejecuta con su shell también en Windows (Git Bash).
     * Busca PHP como los hooks de los agentes: Artisan en una aplicación, el
     * builder en un paquete, y sin `vendor/` deja pasar avisando —antes del
     * `composer install` no hay nada que ejecutar—. Si la guardia falla deja
     * pasar avisando: la CI lo comprobará. Sólo frena lo que la guardia
     * rechaza, y el candado sin una persona que lo confirme.
     *
     * @param  string  $project  la ruta del proyecto desde la raíz del repositorio ('.' si es ella)
     */
    public static function script(string $hook, string $project = '.'): string
    {
        $what = $hook === 'pre-push' ? 'push' : 'commit';
        $verb = $hook === 'pre-push' ? 'empujar' : 'comitear';
        $when = $hook === 'pre-push' ? 'antes de cada push' : 'antes de cada commit';
        $phrase = self::PHRASE;
        $directory = $project === '.' ? '' : '/'.self::shellPath($project);

        return <<<SH
        #!/bin/sh
        # LaraPack: la guardia del contrato {$when} ({$hook}).
        #
        # La ejecuta git, no el agente: frena igual a Claude Code, Cursor, Codex,
        # Copilot, Gemini, Windsurf, Aider o a una persona. Corre
        # `larapack:guard --git={$hook}` sobre lo que se va a {$verb}: controladores
        # con lógica, lo generado que se desvía y excepciones #[HandRolled] sin
        # aprobar. Si cambia .larapack/hand-rolled.lock, lo tiene que confirmar una
        # persona desde una terminal.
        #
        # Lo genera `larapack:skill --hooks` y se reescribe al reinstalarlo; git lo
        # usa con `git config core.hooksPath .githooks`. Un hook de git se puede
        # quitar: la barrera final es la CI (`larapack:ci`).

        top=\$(git rev-parse --show-toplevel) || exit 0

        # El proyecto, y si no tiene vendor/ propio, la raíz del repositorio: en
        # un monorepo los paquetes suelen usar el vendor/ de la aplicación. Los
        # argumentos que git le pasa al hook (pre-push) no se usan: se vacían.
        set --
        for dir in "\$top{$directory}" "\$top"; do
        	cd "\$dir" 2>/dev/null || continue
        	if [ -f artisan ] && [ -f vendor/autoload.php ]; then
        		set -- php artisan
        		break
        	elif [ ! -f artisan ] && [ -f vendor/bin/builder ]; then
        		set -- php vendor/bin/builder
        		break
        	fi
        done

        if [ \$# -eq 0 ] || ! command -v php >/dev/null 2>&1; then
        	echo "LaraPack: sin vendor/ (o sin php) no se comprobó el contrato; lo comprobará la CI." >&2
        	exit 0
        fi

        "\$@" larapack:guard --git={$hook}
        status=\$?

        case \$status in
        0) exit 0 ;;
        2) exit 1 ;;
        3)
        	if (exec </dev/tty) 2>/dev/null; then
        		printf 'LaraPack: si eres el dueño del proyecto y lo apruebas tú, escribe «%s»: ' '{$phrase}' >/dev/tty
        		IFS= read -r answer </dev/tty || answer=
        		if [ "\$answer" = '{$phrase}' ]; then
        			exit 0
        		fi
        		echo "LaraPack: no se confirmó; el {$what} no sigue." >&2
        	else
        		echo "LaraPack: sin una terminal no hay quien lo confirme, y un agente no aprueba excepciones: el {$what} no sigue. El dueño del proyecto lo hace desde su terminal." >&2
        	fi
        	exit 1 ;;
        *)
        	echo "LaraPack: la guardia no pudo comprobar el {$what} (código \$status); sigue, y lo comprobará la CI." >&2
        	exit 0 ;;
        esac

        SH;
    }

    /**
     * Una ruta relativa dentro de comillas dobles de sh: sin `"`, `\`, `$`
     * ni `` ` ``, que la shell interpretaría.
     */
    private static function shellPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if (preg_match('/["\\\\$`\n\r]/', $path) === 1) {
            throw new RuntimeException("La ruta del proyecto ({$path}) tiene caracteres que no se pueden poner en el hook de git. Instálalo a mano.");
        }

        return $path;
    }

    /**
     * Comprueba lo que se va a comitear (`pre-commit`) o a empujar
     * (`pre-push`, con lo que git le pasa por la entrada estándar).
     *
     * @return array{code: int, message: string} code: 0 sigue, 2 bloquea, 3 pide confirmación
     */
    public static function check(string $hook, string $cwd, string $stdin = ''): array
    {
        $top = self::top($cwd);

        if ($top === null) {
            return ['code' => 0, 'message' => ''];
        }

        if ($hook === 'pre-commit') {
            return self::verdict(
                $top,
                self::paths($top, ['diff', '--cached', '--name-only', '-z', '--diff-filter=ACMR']),
                self::paths($top, ['diff', '--cached', '--name-only', '-z']),
                self::paths($top, ['diff', '--name-only', '-z']),
                'commit',
                []
            );
        }

        $head = self::git($top, ['rev-parse', '--verify', '--quiet', 'HEAD']);
        $files = [];
        $changed = [];
        $notes = [];

        foreach (preg_split('/\R/', $stdin) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 4 || preg_match(self::ZERO, $fields[1]) === 1) {
                continue;
            }

            [$localRef, $localSha] = $fields;

            // Lo que no es el HEAD del árbol de trabajo no se puede comprobar
            // aquí: el Verifier lee el disco, y el disco es HEAD.
            if ($head === null || trim($head) !== $localSha) {
                $notes[] = 'LaraPack: '.Guard::inline($localRef).' no es lo que tienes en el árbol de trabajo; no se comprobó aquí, lo comprobará la CI.';

                continue;
            }

            // Los commits que todavía no tiene ningún remoto: lo que de
            // verdad sale de esta máquina.
            $files = [...$files, ...self::paths($top, ['log', '--format=', '--name-only', '-z', '--diff-filter=ACMR', $localSha, '--not', '--remotes'])];
            $changed = [...$changed, ...self::paths($top, ['log', '--format=', '--name-only', '-z', $localSha, '--not', '--remotes'])];
        }

        if ($files === [] && $changed === []) {
            return ['code' => 0, 'message' => implode("\n", $notes)];
        }

        return self::verdict(
            $top,
            array_values(array_unique($files)),
            array_values(array_unique($changed)),
            self::paths($top, ['diff', 'HEAD', '--name-only', '-z']),
            'push',
            $notes
        );
    }

    /**
     * El veredicto sobre unos archivos del repositorio.
     *
     * @param  list<string>  $files  los que se comprueban (añadidos o cambiados), desde la raíz del repositorio
     * @param  list<string>  $changed  todos los cambiados, también los borrados: el candado puede borrarse
     * @param  list<string>  $dirty  los que en el disco no son lo que se comitea o se empuja
     * @param  list<string>  $notes
     * @return array{code: int, message: string}
     */
    private static function verdict(string $top, array $files, array $changed, array $dirty, string $what, array $notes): array
    {
        $projects = self::watched($top, $files);
        $mismatched = [];

        foreach ($projects as $project) {
            foreach ($project['files'] as $fromTop) {
                if (in_array($fromTop, $dirty, true)) {
                    $mismatched[] = $fromTop;
                }
            }
        }

        if ($mismatched !== []) {
            $lines = ['LaraPack: estos archivos tienen en el disco cambios que no van en el '.$what.', y la guardia comprueba el disco:'];

            foreach ($mismatched as $file) {
                $lines[] = '  - '.Guard::inline($file);
            }

            $lines[] = $what === 'commit'
                ? 'Prepáralos enteros (git add) o deja esos cambios fuera del árbol antes de comitear: lo que no se puede comprobar no se da por bueno.'
                : 'Comítealos o deja esos cambios fuera del árbol antes de empujar: lo que no se puede comprobar no se da por bueno.';

            return ['code' => self::BLOCK, 'message' => implode("\n", [...$notes, ...$lines])];
        }

        $messages = [];

        foreach ($projects as $root => $project) {
            $findings = Guard::findings($root, $project['managed'] ? [] : ControllerShape::CHECKS);
            $relevant = self::relevant($findings, array_keys($project['files']));

            if ($relevant === []) {
                continue;
            }

            $message = Guard::message(
                'LaraPack: lo que vas a '.($what === 'commit' ? 'comitear' : 'empujar').' se sale del contrato'
                    .(rtrim($root, '/') === rtrim($top, '/') ? '' : ' en '.Guard::inline((string) Guard::relative($top, $root))).'; el '.$what.' no sigue.',
                $relevant,
                $root
            );

            if (array_intersect(array_column($relevant, 'check'), [ControllerShape::HAND_ROLLED, ControllerShape::HAND_ROLLED_UNAPPROVED, ControllerShape::HAND_ROLLED_STALE]) !== []) {
                $message .= "\n".'Una excepción #[HandRolled] la aprueba el dueño del proyecto: él corre `'.Guard::commandPrefix($root).' larapack:hand-rolled approve`, revisa '.HandRolledLock::PATH.' y lo comitea desde su terminal. Un agente no: devuelve la acción a `return $request->handle();`.';
            }

            $messages[] = $message;
        }

        if ($messages !== []) {
            return ['code' => self::BLOCK, 'message' => implode("\n\n", [...$notes, ...$messages])];
        }

        $locks = array_values(array_filter($changed, fn (string $file): bool => preg_match(self::LOCK, $file) === 1));

        if ($locks !== []) {
            $lines = [];

            foreach ($locks as $lock) {
                $lines[] = 'LaraPack: el '.$what.' cambia '.Guard::inline($lock).', la aprobación de las excepciones #[HandRolled].';
            }

            $lines[] = 'Sólo la da el dueño del proyecto, en persona. Un agente no edita ese archivo ni corre `larapack:hand-rolled approve` o `revoke`.';

            return ['code' => self::CONFIRM, 'message' => implode("\n", [...$notes, ...$lines])];
        }

        return ['code' => 0, 'message' => implode("\n", $notes)];
    }

    /**
     * Los archivos que son arquitectura, por proyecto: el más cercano con
     * `composer.json` o manifiesto, como en la guardia de los agentes. En un
     * monorepo, cada paquete con lo suyo.
     *
     * @param  list<string>  $files
     * @return array<string, array{managed: bool, files: array<string, string>}> por raíz: si tiene manifiesto, y relativo al proyecto => relativo al repositorio
     */
    private static function watched(string $top, array $files): array
    {
        $projects = [];

        foreach ($files as $fromTop) {
            $absolute = rtrim($top, '/').'/'.$fromTop;

            if (Guard::isThirdParty($absolute)) {
                continue;
            }

            $root = Guard::projectOf($absolute);

            if ($root === null || ($relative = Guard::relative($root, $absolute)) === null) {
                continue;
            }

            $manifest = new Manifest($root);
            $managed = is_file($manifest->path());

            // El candado se mira siempre: un candado roto o que aprueba lo que
            // ya no existe es un hallazgo suyo.
            if (preg_match(self::LOCK, $relative) !== 1) {
                if (! $managed && ! Guard::isShapeFile($relative)) {
                    continue;
                }

                if (! Guard::watches($relative, $manifest->snapshot())) {
                    continue;
                }
            }

            $projects[$root]['managed'] = $managed;
            $projects[$root]['files'][$relative] = $fromTop;
        }

        return $projects;
    }

    /**
     * Los hallazgos de esos archivos que frenan: los errores, y los avisos
     * que `verify --strict` cuenta y son de una excepción sin aprobar.
     *
     * @param  array<int, array<string, mixed>>  $findings  los de Guard::findings()
     * @param  list<string>  $files
     * @return list<array<string, mixed>>
     */
    private static function relevant(array $findings, array $files): array
    {
        return array_values(array_filter($findings, function (array $finding) use ($files): bool {
            $file = $finding['file'] ?? null;
            $level = $finding['level'] ?? null;

            if (! is_string($file) || ! in_array(preg_replace('#^\./#', '', str_replace('\\', '/', $file)), $files, true)) {
                return false;
            }

            return $level === Verifier::ERROR
                || ($level === Verifier::WARNING && in_array($finding['check'] ?? null, self::STRICT_WARNINGS, true));
        }));
    }

    /**
     * Escribe los scripts en `.githooks/` de la raíz del repositorio y le
     * dice a git que los use.
     *
     * Nada de lo que ya es del proyecto se pisa:
     *
     * - un `.githooks/pre-commit` que no es de LaraPack se queda, y el de
     *   LaraPack va a `.githooks/larapack-pre-commit`, para llamarlo desde él;
     * - `core.hooksPath` sólo se pone si no lo está y si `.git/hooks` no tiene
     *   hooks propios, que dejarían de correr; si no, se dice qué añadir.
     *
     * @return array{directory: string, written: list<string>, current: list<string>, foreign: list<string>, hooks_path: 'set'|'present'|'manual'|'no-repository', instructions: list<string>}
     */
    public static function install(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $top = self::top($root);
        $base = $top ?? $root;
        $project = $top === null ? '.' : (Guard::relative($top, $root) ?? '.');
        $directory = $base.'/'.self::DIRECTORY;
        $written = [];
        $current = [];
        $foreign = [];
        $instructions = [];

        foreach (self::HOOKS as $hook) {
            $target = $directory.'/'.$hook;
            $contents = self::script($hook, $project);

            if (is_file($target) && ! str_contains((string) file_get_contents($target), self::SIGNATURE)) {
                $foreign[] = $target;
                $target = $directory.'/larapack-'.$hook;
                $instructions[] = sprintf('%s/%s no es de LaraPack y se deja como está: añádele `sh %s/larapack-%s "$@" || exit $?` para que también compruebe el contrato.', self::DIRECTORY, $hook, self::DIRECTORY, $hook);
            }

            if (is_file($target) && file_get_contents($target) === $contents) {
                $current[] = $target;
            } else {
                AgentHooks::writeInside($base, $target, $contents);
                $written[] = $target;
            }

            @chmod($target, 0755);
        }

        if ($top === null) {
            $instructions[] = 'El proyecto todavía no es un repositorio git. Cuando lo sea: git config core.hooksPath '.self::DIRECTORY;

            return ['directory' => $directory, 'written' => $written, 'current' => $current, 'foreign' => $foreign, 'hooks_path' => 'no-repository', 'instructions' => $instructions];
        }

        $hooksPath = self::git($top, ['config', '--get', 'core.hooksPath']);
        $hooksPath = $hooksPath === null ? null : trim($hooksPath);

        if ($hooksPath !== null && $hooksPath !== '') {
            if (self::pointsHere($top, $hooksPath)) {
                $status = 'present';
            } else {
                $status = 'manual';
                $instructions[] = sprintf('core.hooksPath ya apunta a %s, y no se cambia: desde su pre-commit y su pre-push llama a `sh %s/<hook> "$@" || exit $?`.', Guard::inline($hooksPath), self::DIRECTORY);
            }
        } elseif (($own = self::ownGitHooks($top)) !== []) {
            $status = 'manual';
            $instructions[] = sprintf('.git/hooks tiene hooks propios (%s) que dejarían de correr con core.hooksPath: muévelos a %s/ y corre `git config core.hooksPath %s`, o llama desde ellos a `sh %s/<hook> "$@" || exit $?`.', implode(', ', $own), self::DIRECTORY, self::DIRECTORY, self::DIRECTORY);
        } elseif (self::git($top, ['config', 'core.hooksPath', self::DIRECTORY]) !== null) {
            $status = 'set';
        } else {
            $status = 'manual';
            $instructions[] = 'No se pudo configurar git: corre `git config core.hooksPath '.self::DIRECTORY.'`.';
        }

        return ['directory' => $directory, 'written' => $written, 'current' => $current, 'foreign' => $foreign, 'hooks_path' => $status, 'instructions' => $instructions];
    }

    /**
     * En qué estado está la capa de git en el proyecto, para el diagnóstico.
     *
     * Activa quiere decir que git ejecutará la guardia en cada commit y en
     * cada push: los dos scripts son de LaraPack (o el de LaraPack se llama
     * desde el del proyecto), son ejecutables y git los usa.
     *
     * @return array{repository: bool, hooks: array<string, 'ours'|'chained'|'foreign'|'missing'|'not-executable'>, hooks_path: string|null, active: bool}
     */
    public static function status(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $top = self::top($root);
        $base = $top ?? $root;
        $hooksPath = $top === null ? null : self::git($top, ['config', '--get', 'core.hooksPath']);
        $hooksPath = $hooksPath === null || trim($hooksPath) === '' ? null : trim($hooksPath);

        // El directorio del que git leerá los hooks.
        $runs = match (true) {
            $top === null => null,
            $hooksPath === null => self::gitHooksDirectory($top),
            default => self::isAbsolute($hooksPath) ? $hooksPath : $top.'/'.$hooksPath,
        };

        $hooks = [];

        foreach (self::HOOKS as $hook) {
            $file = $runs === null ? $base.'/'.self::DIRECTORY.'/'.$hook : rtrim($runs, '/').'/'.$hook;
            $contents = is_file($file) ? (string) file_get_contents($file) : null;

            $hooks[$hook] = match (true) {
                $contents === null => 'missing',
                ! str_contains($contents, self::SIGNATURE) && ! (str_contains($contents, self::DIRECTORY.'/') && str_contains($contents, $hook)) => 'foreign',
                PHP_OS_FAMILY !== 'Windows' && ! is_executable($file) => 'not-executable',
                str_contains($contents, self::SIGNATURE) => 'ours',
                default => 'chained',
            };
        }

        $active = $top !== null && array_diff(array_values($hooks), ['ours', 'chained']) === [];

        return ['repository' => $top !== null, 'hooks' => $hooks, 'hooks_path' => $hooksPath, 'active' => $active];
    }

    /**
     * Los hooks activos de `.git/hooks` (los `.sample` no cuentan).
     *
     * @return list<string>
     */
    private static function ownGitHooks(string $top): array
    {
        $directory = self::gitHooksDirectory($top);
        $own = [];

        foreach ($directory === null ? [] : (scandir($directory) ?: []) as $entry) {
            if ($entry !== '.' && $entry !== '..' && ! str_ends_with($entry, '.sample') && is_file($directory.'/'.$entry)) {
                $own[] = $entry;
            }
        }

        return $own;
    }

    private static function gitHooksDirectory(string $top): ?string
    {
        $path = self::git($top, ['rev-parse', '--git-path', 'hooks']);

        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));

        return self::isAbsolute($path) ? $path : $top.'/'.$path;
    }

    private static function pointsHere(string $top, string $hooksPath): bool
    {
        $hooksPath = rtrim(str_replace('\\', '/', $hooksPath), '/');
        $absolute = self::isAbsolute($hooksPath) ? $hooksPath : $top.'/'.$hooksPath;
        $real = realpath($absolute);
        $expected = realpath($top.'/'.self::DIRECTORY);

        return $real !== false && $expected !== false ? $real === $expected : $hooksPath === self::DIRECTORY;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * La raíz del repositorio, o null si no lo es.
     */
    public static function top(string $directory): ?string
    {
        $top = self::git($directory, ['rev-parse', '--show-toplevel']);

        if ($top === null || trim($top) === '') {
            return null;
        }

        $top = trim($top);

        return rtrim(str_replace('\\', '/', (string) (realpath($top) ?: $top)), '/');
    }

    /**
     * Las rutas de una orden de git con `-z`, desde la raíz del repositorio.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function paths(string $top, array $arguments): array
    {
        $output = self::git($top, $arguments);

        if ($output === null) {
            return [];
        }

        $paths = array_map(fn (string $path): string => trim($path, "\n\r"), explode("\0", $output));

        return array_values(array_unique(array_filter($paths, fn (string $path): bool => $path !== '')));
    }

    /**
     * La salida de git, o null si falla o no está.
     *
     * @param  list<string>  $arguments
     */
    private static function git(string $directory, array $arguments): ?string
    {
        $process = @proc_open(
            ['git', '-C', $directory, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($output) ? $output : null;
    }
}
