<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * La CI como barrera del contrato: el workflow que corre
 * `larapack:verify --strict`, las líneas de CODEOWNERS que hacen que la
 * aprobación de las excepciones pase por el dueño, y la orden que protege la
 * rama con las dos cosas.
 *
 * Un hook se quita, y un agente que no lo tiene instalado no lo ve. La rama
 * protegida no depende de que nadie se acuerde de nada: un cambio que no pasa
 * `verify --strict` no entra, y uno que toca el candado espera la revisión
 * del dueño.
 *
 * Todo lo que decide sale del proyecto —composer.json, composer.lock, git—, y
 * siempre igual para el mismo proyecto: el workflow de dos ejecuciones es el
 * mismo byte a byte, y por eso se puede saber si alguien lo editó.
 */
final class CiGate
{
    public const WORKFLOW = '.github/workflows/larapack.yml';

    /**
     * El nombre del job, que es el del check que exige la protección de la
     * rama. El de los paquetes nuevos (Stubs/Package/TestsWorkflow.txt) se
     * llama igual.
     */
    public const JOB = 'LaraPack verify';

    /**
     * Donde GitHub busca CODEOWNERS, en su orden: usa el primero que existe.
     * Crear `.github/CODEOWNERS` en un proyecto que ya tiene uno en la raíz
     * dejaría el suyo sin efecto.
     */
    private const CODEOWNERS = ['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS'];

    /**
     * La raíz es una aplicación si tiene Artisan; si no, un paquete.
     */
    public static function isApplication(string $root): bool
    {
        return is_file(self::join($root, 'artisan'));
    }

    /**
     * El workflow de la puerta.
     *
     * @param  string|null  $branch  la rama por defecto; null, las dos habituales
     */
    public static function workflow(string $root, ?string $branch): string
    {
        $application = self::isApplication($root);
        [$php, $why] = self::phpVersion($root);
        $locked = is_file(self::join($root, 'composer.lock'));
        $extensions = self::extensions($root);
        $branches = $branch === null ? '[main, master]' : '['.self::yamlScalar($branch).']';
        $binary = $application ? 'php artisan' : 'php vendor/bin/builder';

        $lines = [
            'name: LaraPack',
            '',
            '# Generado por `larapack:ci`. El contrato de LaraPack como puerta de la rama:',
            '# la protección de la rama exige el check «'.self::JOB.'», que es el nombre',
            '# del job. Si se cambia, la protección espera un check que ya no llega.',
            '# --strict cuenta los avisos como fallos: una excepción #[HandRolled] sin',
            '# aprobar en .larapack/hand-rolled.lock no entra.',
            'on:',
            '    push:',
            "        branches: {$branches}",
            '    pull_request:',
            '',
            'jobs:',
            '    verify:',
            '        name: '.self::JOB,
            '        runs-on: ubuntu-latest',
            '',
            '        steps:',
            '            - uses: actions/checkout@v5',
            '',
            '            - uses: shivammathur/setup-php@v2',
            '              with:',
            "                  # {$why}",
            "                  php-version: '{$php}'",
            ...($extensions === [] ? [] : ['                  extensions: '.implode(', ', $extensions)]),
            '                  coverage: none',
            '',
        ];

        if ($application && is_file(self::join($root, '.env.example'))) {
            $lines = [...$lines,
                '            # Artisan arranca la aplicación; verify no toca la base ni usa APP_KEY.',
                '            - name: Preparar .env',
                '              run: test -f .env || cp .env.example .env',
                '',
            ];
        }

        return implode("\n", [...$lines,
            '            - name: Instalar dependencias',
            '              run: composer '.($locked ? 'install' : 'update').' --prefer-dist --no-interaction --no-progress',
            '',
            '            - name: Contrato de LaraPack',
            "              run: {$binary} larapack:verify --strict --format=json",
        ])."\n";
    }

    /**
     * La versión de PHP del job, y de dónde sale.
     *
     * La que fija `config.platform.php`, si la hay: es contra la que Composer
     * resolvió. Si no, la mínima con la que se puede instalar el proyecto: la
     * mayor de las cotas inferiores del `require.php` del proyecto y del de
     * cada paquete de composer.lock. Con `^8.3` en composer.json y un lock
     * resuelto en 8.4 —Symfony 8 pide 8.4—, `composer install` falla en 8.3.
     *
     * @return array{0: string, 1: string} versión mayor.menor y el porqué
     */
    public static function phpVersion(string $root): array
    {
        $composer = self::readJson(self::join($root, 'composer.json')) ?? [];
        $platform = $composer['config']['platform']['php'] ?? null;

        if (is_string($platform) && ($version = self::minor($platform)) !== null) {
            return [$version, 'La de config.platform.php en composer.json: contra la que resuelve Composer.'];
        }

        $declared = self::lowerBound($composer['require']['php'] ?? null);
        $lock = self::readJson(self::join($root, 'composer.lock'));
        $locked = null;

        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            $bound = is_array($package) ? self::lowerBound($package['require']['php'] ?? null) : null;

            if ($bound !== null && ($locked === null || version_compare($bound, $locked, '>'))) {
                $locked = $bound;
            }
        }

        if ($locked !== null && ($declared === null || version_compare($locked, $declared, '>'))) {
            return [$locked, 'La mínima con la que se instala composer.lock: algún paquete bloqueado la exige.'];
        }

        if ($declared !== null) {
            return [$declared, 'La mínima que admite require.php en composer.json.'];
        }

        $baseline = self::lowerBound((new Ecosystem)->rules()['php']['require'] ?? null) ?? '8.4';

        return [$baseline, 'composer.json no declara php: la mínima de la línea base de LaraPack.'];
    }

    /**
     * Las extensiones que el proyecto declara (`ext-*`), para que el job no
     * dependa de cuáles traiga la imagen.
     *
     * @return list<string>
     */
    public static function extensions(string $root): array
    {
        $composer = self::readJson(self::join($root, 'composer.json')) ?? [];
        $extensions = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(is_array($composer[$section] ?? null) ? $composer[$section] : []) as $package) {
                if (preg_match('/^ext-([a-z0-9_]+)$/i', (string) $package, $match) === 1) {
                    $extensions[] = strtolower($match[1]);
                }
            }
        }

        $extensions = array_values(array_unique($extensions));
        sort($extensions, SORT_STRING);

        return $extensions;
    }

    /**
     * La raíz del repositorio git que contiene el proyecto, o null.
     */
    public static function gitTop(string $root): ?string
    {
        $top = self::git($root, ['rev-parse', '--show-toplevel']);

        return $top === null || $top === '' ? null : str_replace('\\', '/', (string) (realpath($top) ?: $top));
    }

    /**
     * La rama por defecto según el remoto (`origin/HEAD`), o null si el clon
     * no la conoce. No se adivina por la rama actual: puede ser una de trabajo.
     */
    public static function defaultBranch(string $root): ?string
    {
        $head = self::git($root, ['symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']);

        return $head === null || ! str_starts_with($head, 'origin/') ? null : substr($head, strlen('origin/'));
    }

    /**
     * `dueño/repositorio` de GitHub según el remoto `origin`, o null.
     */
    public static function repository(string $root): ?string
    {
        $url = self::git($root, ['remote', 'get-url', 'origin']);

        if ($url === null || preg_match('#github\.com[:/]+([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $url, $match) !== 1) {
            return null;
        }

        return "{$match[1]}/{$match[2]}";
    }

    /**
     * Si es un dueño que CODEOWNERS entiende: `@usuario`, `@organización/equipo`
     * o un correo.
     */
    public static function isOwner(string $owner): bool
    {
        return preg_match('#^@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:/[A-Za-z0-9._-]+)?$#', $owner) === 1
            || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $owner) === 1;
    }

    /**
     * El CODEOWNERS que GitHub lee en este proyecto, relativo a la raíz: el
     * primero que exista, o `.github/CODEOWNERS` si no hay ninguno.
     */
    public static function codeownersFile(string $root): string
    {
        foreach (self::CODEOWNERS as $candidate) {
            if (is_file(self::join($root, $candidate))) {
                return $candidate;
            }
        }

        return self::CODEOWNERS[0];
    }

    /**
     * Lo que tiene que revisar el dueño: el candado de las excepciones, y lo
     * que sostiene la puerta —este workflow y el propio CODEOWNERS—. Si el
     * workflow o CODEOWNERS se pudieran cambiar sin su revisión, quitar la
     * puerta costaría un commit.
     *
     * @return list<string>
     */
    public static function ownedPaths(string $codeowners): array
    {
        return ['/'.HandRolledLock::PATH, '/'.self::WORKFLOW, '/'.$codeowners];
    }

    /**
     * Fusiona las líneas de LaraPack en un CODEOWNERS.
     *
     * No borra ni reordena nada, y no duplica: un patrón que ya está con
     * estos dueños se deja. Uno que ya está con otros dueños tampoco se toca
     * —en CODEOWNERS gana la última línea que coincide, y añadir otra le
     * quitaría el archivo a quien lo tenía—: se devuelve en `foreign` para
     * decirlo.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $owners
     * @return array{contents: string, added: list<string>, present: list<string>, foreign: list<string>}
     */
    public static function mergeCodeowners(string $current, array $paths, array $owners): array
    {
        $existing = [];

        foreach (preg_split('/\R/', $current) ?: [] as $line) {
            $code = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
            $tokens = $code === '' ? [] : (preg_split('/\s+/', $code) ?: []);

            if ($tokens !== []) {
                $existing[ltrim($tokens[0], '/')][] = ['line' => trim($line), 'owners' => array_slice($tokens, 1)];
            }
        }

        $added = [];
        $present = [];
        $foreign = [];

        foreach ($paths as $path) {
            $matches = $existing[ltrim($path, '/')] ?? [];

            if ($matches === []) {
                $added[] = $path.' '.implode(' ', $owners);

                continue;
            }

            // La última línea del patrón es la que manda.
            $last = $matches[count($matches) - 1];

            if (array_diff(array_map('strtolower', $owners), array_map('strtolower', $last['owners'])) === []) {
                $present[] = $last['line'];
            } else {
                $foreign[] = $last['line'];
            }
        }

        $contents = $current;

        if ($added !== []) {
            $separator = $current === '' ? '' : (str_ends_with($current, "\n") ? "\n" : "\n\n");
            $contents = $current.$separator.implode("\n", [
                '# LaraPack (larapack:ci): la aprobación de las excepciones #[HandRolled] y la',
                '# puerta de la CI las revisa el dueño del proyecto.',
                ...$added,
            ])."\n";
        }

        return ['contents' => $contents, 'added' => $added, 'present' => $present, 'foreign' => $foreign];
    }

    /**
     * La orden que protege la rama, para que la corra el dueño. Nunca se
     * ejecuta desde aquí.
     *
     * Una sola línea con los campos de `gh api` y no un heredoc: se copia y se
     * pega igual en bash, en zsh y en PowerShell, y un heredoc sangrado no
     * termina nunca. Cada campo va entre comillas simples porque los
     * corchetes son un patrón de la shell (zsh falla si no coincide con nada).
     *
     * PUT sustituye la protección entera: con `strict: false` no obliga a
     * rebasar cada PR, y con `enforce_admins: false` el administrador puede
     * fusionar su propio cambio del candado —GitHub no deja aprobar un PR
     * propio—.
     */
    public static function protectionCommand(string $repository, string $branch): string
    {
        // -F convierte true, false, null y los números a su tipo JSON; -f
        // manda el texto tal cual, que es lo que necesita el nombre del check.
        $fields = [
            ['-F', 'required_status_checks[strict]=false'],
            ['-f', 'required_status_checks[contexts][]='.self::JOB],
            ['-F', 'enforce_admins=false'],
            ['-F', 'required_pull_request_reviews[require_code_owner_reviews]=true'],
            ['-F', 'required_pull_request_reviews[required_approving_review_count]=1'],
            ['-F', 'restrictions=null'],
        ];

        $command = "gh api --method PUT repos/{$repository}/branches/{$branch}/protection";

        foreach ($fields as [$flag, $value]) {
            $command .= " {$flag} '{$value}'";
        }

        return $command;
    }

    private static function lowerBound(mixed $constraint): ?string
    {
        if (! is_string($constraint) || trim($constraint) === '') {
            return null;
        }

        try {
            $version = (new VersionParser)->parseConstraints($constraint)->getLowerBound()->getVersion();
        } catch (UnexpectedValueException) {
            return null;
        }

        $minor = self::minor($version);

        // Sin cota (`*`, `>=0`) no dice nada de PHP.
        return $minor === null || version_compare($minor, '5.0', '<') ? null : $minor;
    }

    private static function minor(string $version): ?string
    {
        return preg_match('/^v?(\d+)\.(\d+)/', trim($version), $match) === 1 ? "{$match[1]}.{$match[2]}" : null;
    }

    private static function yamlScalar(string $value): string
    {
        return preg_match('#^[A-Za-z0-9._/-]+$#', $value) === 1 ? $value : "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private static function join(string $root, string $relative): string
    {
        return rtrim(str_replace('\\', '/', $root), '/').'/'.$relative;
    }

    /**
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
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($output) ? trim($output) : null;
    }
}
