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
 * del dueño. Con una condición: que todo entre por PR y nadie empuje como
 * administrador saltándose la protección. Por eso la orden que se imprime
 * pide `enforce_admins=true` salvo que se diga lo contrario: un orquestador
 * que sube el SHA verificado directo a la rama con la cuenta del dueño se
 * salta el check y la revisión en cada push.
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
     * El runner por defecto: el alojado de GitHub.
     */
    public const DEFAULT_RUNNER = 'ubuntu-latest';

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
     * @param  list<string>  $runsOn  las etiquetas del runner (runsOn()); vacío, el alojado
     */
    public static function workflow(string $root, ?string $branch, array $runsOn = []): string
    {
        $branches = $branch === null ? '[main, master]' : '['.self::yamlScalar($branch).']';
        $binary = self::binary($root);

        return implode("\n", [
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
            '        runs-on: '.self::runsOnYaml($runsOn),
            '        # Sólo lectura: verify no escribe nada, y los dos últimos pasos sólo',
            '        # leen las reglas de la rama y las corridas de aprobación.',
            '        permissions:',
            '            contents: read',
            '            actions: read',
            '',
            '        steps:',
            ...self::setupSteps($root),
            '            - name: Contrato de LaraPack',
            "              run: {$binary} larapack:verify --strict --format=json",
            '',
            ...self::namedApprovalsStep($binary),
            '',
            ...self::protectionStep($binary),
        ])."\n";
    }

    /**
     * El workflow de aprobación sin terminal (NamedApproval), con la misma
     * preparación que el de la puerta.
     *
     * @param  list<string>  $runsOn  las etiquetas del runner (runsOn()); vacío, el alojado
     */
    public static function approvalWorkflow(string $root, array $runsOn = []): string
    {
        return NamedApproval::workflow(self::binary($root), self::setupSteps($root), self::runsOnYaml($runsOn));
    }

    /**
     * Cómo se llama a LaraPack en el proyecto: Artisan en una aplicación, el
     * binario en un paquete.
     */
    public static function binary(string $root): string
    {
        return self::isApplication($root) ? 'php artisan' : 'php vendor/bin/builder';
    }

    /**
     * Los pasos que dejan el proyecto listo para correr LaraPack: el código,
     * PHP con las extensiones que declara, `.env` si es una aplicación y las
     * dependencias.
     *
     * @return list<string>
     */
    private static function setupSteps(string $root): array
    {
        [$php, $why] = self::phpVersion($root);
        $locked = is_file(self::join($root, 'composer.lock'));
        $extensions = self::extensions($root);

        $lines = [
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

        if (self::isApplication($root) && is_file(self::join($root, '.env.example'))) {
            $lines = [...$lines,
                '            # Artisan arranca la aplicación; verify no toca la base ni usa APP_KEY.',
                '            - name: Preparar .env',
                '              run: test -f .env || cp .env.example .env',
                '',
            ];
        }

        return [...$lines,
            '            - name: Instalar dependencias',
            '              run: composer '.($locked ? 'install' : 'update').' --prefer-dist --no-interaction --no-progress',
            '',
        ];
    }

    /**
     * El paso que enseña en el PR cada aprobación sin terminal del candado
     * (`approved_by`) y comprueba con GitHub que su corrida es la del
     * workflow de aprobación, lanzada a mano por esa persona.
     *
     * Sale como anotación sobre la línea del candado —en el diff del PR,
     * donde mira quien revisa— y falla el job si alguna no se puede
     * comprobar: una aprobación con un nombre y una corrida inventados no
     * entra. Corre también si verify falló (`!cancelled()`).
     *
     * @return list<string>
     */
    private static function namedApprovalsStep(string $binary): array
    {
        return [
            '            # Cada aprobación sin terminal del candado (approved_by), a la vista en',
            '            # el diff del PR y comprobada con GitHub: la corrida tiene que ser la del',
            '            # workflow de aprobación, lanzada a mano por quien dice. Si no, falla.',
            '            - name: Aprobaciones sin terminal',
            '              if: ${{ !cancelled() }}',
            '              env:',
            '                  GH_TOKEN: ${{ github.token }}',
            "              run: {$binary} larapack:hand-rolled list --format=github",
        ];
    }

    /**
     * El último paso del job: si la rama por defecto exige este check.
     *
     * La puerta sólo vale si la rama la exige, y eso lo decide el dueño en
     * GitHub. Aquí se comprueba en cada corrida con el token del job —leer
     * las reglas de la rama no pide más que `contents: read`— y, mientras no
     * la exija, se avisa con un `::warning` y los pasos en el resumen.
     *
     * No falla el job (`continue-on-error`): el check que se exige es éste, y
     * que su verde dependiera de la protección sería circular. Corre también
     * si verify falló (`!cancelled()`): es cuando más importa saberlo.
     *
     * @return list<string>
     */
    private static function protectionStep(string $binary): array
    {
        return [
            '            # La puerta sólo vale si la rama exige este check, y eso lo decide el',
            '            # dueño en GitHub (larapack:ci imprime cómo). Aquí se comprueba en cada',
            '            # corrida y, si no lo exige, se avisa con los pasos en el resumen. No',
            '            # falla el job: el check exigido es éste, y sería circular.',
            '            - name: La rama exige este check',
            '              if: ${{ !cancelled() }}',
            '              continue-on-error: true',
            '              env:',
            '                  GH_TOKEN: ${{ github.token }}',
            "              run: {$binary} larapack:ci --check --format=github",
        ];
    }

    /**
     * Las etiquetas del runner a partir de `--runs-on`: una por valor
     * (`--runs-on=self-hosted --runs-on=linux`), o un solo valor con una
     * lista JSON (`--runs-on='["self-hosted","linux"]'`).
     *
     * Sólo letras, dígitos, `.`, `_` y `-`: es lo que admite una etiqueta de
     * runner, y lo que se puede escribir en el YAML sin comillas ni
     * expresiones.
     *
     * @param  list<string>  $values
     * @return list<string>
     *
     * @throws UnexpectedValueException si alguna no es una etiqueta
     */
    public static function runsOn(array $values): array
    {
        $values = array_values(array_filter(array_map('trim', $values), fn (string $value): bool => $value !== ''));

        if (count($values) === 1 && str_starts_with($values[0], '[')) {
            $decoded = json_decode($values[0], true);

            if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
                throw new UnexpectedValueException('--runs-on con [ tiene que ser una lista JSON de etiquetas, como \'["self-hosted","linux"]\'.');
            }

            $values = $decoded;
        }

        $labels = [];

        foreach ($values as $label) {
            if (! is_string($label) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $label) !== 1) {
                throw new UnexpectedValueException('--runs-on: «'.(is_string($label) ? $label : json_encode($label)).'» no es una etiqueta de runner (letras, dígitos, ., _ y -).');
            }

            $labels[] = $label;
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  list<string>  $labels
     */
    private static function runsOnYaml(array $labels): string
    {
        return match (count($labels)) {
            0 => self::DEFAULT_RUNNER,
            1 => $labels[0],
            default => '['.implode(', ', $labels).']',
        };
    }

    /**
     * Dónde corren los otros workflows del proyecto: el `runs-on` de cada uno,
     * con los archivos que lo usan. Un proyecto que se quedó sin minutos de
     * los runners alojados y corre todo en los suyos no quiere un job más en
     * `ubuntu-latest`: o gasta minutos de pago en cada PR, o no arranca nunca
     * y el check exigido no llega.
     *
     * Sólo se lee, sin interpretar expresiones: un `runs-on: ${{ … }}` se
     * enseña tal cual, sin proponerlo.
     *
     * @return array<string, list<string>> el valor tal como está escrito => archivos
     */
    public static function otherRunners(string $root): array
    {
        $found = [];
        $files = [
            ...(glob(self::join($root, '.github/workflows/*.yml')) ?: []),
            ...(glob(self::join($root, '.github/workflows/*.yaml')) ?: []),
        ];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            if (basename($file) === basename(self::WORKFLOW)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match('/^(\s*)runs-on:\s*(.*)/', self::withoutComment($line), $match) !== 1) {
                    continue;
                }

                $value = trim($match[2]);

                // La lista en bloque: `runs-on:` y debajo `- etiqueta`.
                if ($value === '') {
                    $items = [];

                    for ($next = $index + 1; isset($lines[$next]) && preg_match('/^\s*-\s*(\S.*?)\s*$/', self::withoutComment($lines[$next]), $item) === 1; $next++) {
                        $items[] = trim($item[1], '\'"');
                    }

                    $value = $items === [] ? '' : '['.implode(', ', $items).']';
                }

                if ($value !== '' && ! in_array(basename($file), $found[$value] ?? [], true)) {
                    $found[$value][] = basename($file);
                }
            }
        }

        return $found;
    }

    /**
     * Las etiquetas de un `runs-on` escrito en YAML, si son sólo etiquetas
     * (`[self-hosted, linux]` o `ubuntu-latest`); null si es otra cosa —una
     * expresión, un grupo—.
     *
     * @return list<string>|null
     */
    public static function labelsOf(string $value): ?array
    {
        $inner = preg_match('/^\[(.*)\]$/', $value, $match) === 1 ? $match[1] : $value;
        $labels = array_map(fn (string $label): string => trim(trim($label), '\'"'), explode(',', $inner));

        try {
            return $labels === [''] ? null : self::runsOn($labels);
        } catch (UnexpectedValueException) {
            return null;
        }
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

        $extensions = array_unique($extensions);
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
     * que sostiene la puerta —este workflow, el de aprobación sin terminal
     * (NamedApproval) y el propio CODEOWNERS—. Si el workflow o CODEOWNERS se
     * pudieran cambiar sin su revisión, quitar la puerta costaría un commit.
     * El de aprobación va aunque el proyecto no lo tenga: si no, cualquiera
     * podría añadir uno que aprobara lo que quisiera.
     *
     * @return list<string>
     */
    public static function ownedPaths(string $codeowners): array
    {
        return ['/'.HandRolledLock::PATH, '/'.self::WORKFLOW, '/'.NamedApproval::WORKFLOW, '/'.$codeowners];
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
            $code = trim(self::withoutComment($line));
            $tokens = $code === '' ? [] : (preg_split('/\s+/', $code) ?: []);

            if ($tokens !== []) {
                // El patrón, y detrás sus dueños.
                $pattern = array_shift($tokens);
                $existing[ltrim($pattern, '/')][] = ['line' => trim($line), 'owners' => $tokens];
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
     * Si la rama se puede escribir tal cual en la orden que se imprime. Un
     * nombre de rama de git admite `;`, `$`, `&`, `|`, paréntesis y comillas
     * invertidas, y lo decide el remoto: una orden que el dueño va a pegar en
     * su terminal no puede llevar eso. Lo que no pasa se sustituye por un
     * marcador que rellena él.
     */
    public static function isSafeBranch(string $branch): bool
    {
        return preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#', $branch) === 1 && ! str_contains($branch, '..');
    }

    /**
     * La orden que protege la rama, para que la corra el dueño. Nunca se
     * ejecuta desde aquí.
     *
     * Una sola línea con los campos de `gh api` y no un heredoc: se copia y se
     * pega igual en bash, en zsh y en PowerShell, y un heredoc sangrado no
     * termina nunca. Cada argumento va entre comillas simples —el endpoint
     * también—: los corchetes son un patrón de la shell (zsh falla si no
     * coincide con nada), y el repositorio y la rama salen de git.
     *
     * PUT sustituye la protección entera. Con `strict: false` no obliga a
     * rebasar cada PR. Con `enforce_admins: true` —lo de fábrica— ni un
     * administrador se salta el check ni la revisión: es lo que hace que la
     * puerta valga cuando alguien empuja directo a la rama con una cuenta de
     * administrador. El precio es que GitHub no deja aprobar un PR propio, así
     * que los PR del agente tienen que salir de una cuenta que no sea la del
     * único dueño de código.
     */
    public static function protectionCommand(string $repository, string $branch, bool $enforceAdmins = true): string
    {
        // -F convierte true, false, null y los números a su tipo JSON; -f
        // manda el texto tal cual, que es lo que necesita el nombre del check.
        $fields = [
            ['-F', 'required_status_checks[strict]=false'],
            ['-f', 'required_status_checks[contexts][]='.self::JOB],
            ['-F', 'enforce_admins='.($enforceAdmins ? 'true' : 'false')],
            ['-F', 'required_pull_request_reviews[require_code_owner_reviews]=true'],
            ['-F', 'required_pull_request_reviews[required_approving_review_count]=1'],
            ['-F', 'restrictions=null'],
        ];

        $command = 'gh api --method PUT '.self::shellQuote("repos/{$repository}/branches/{$branch}/protection");

        foreach ($fields as [$flag, $value]) {
            $command .= " {$flag} ".self::shellQuote($value);
        }

        return $command;
    }

    /**
     * Entre comillas simples, para bash, zsh y PowerShell: una comilla simple
     * dentro se cierra, se escapa y se vuelve a abrir.
     */
    public static function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private static function lowerBound(mixed $constraint): ?string
    {
        // Vacía o en blanco no es una restricción: VersionParser la rechaza
        // como cualquier otra que no entiende.
        if (! is_string($constraint)) {
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
        $decoded = is_file($file) ? json_decode(Files::read($file), true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Una línea de YAML o de CODEOWNERS sin su comentario: `#` al principio
     * o detrás de un espacio.
     */
    private static function withoutComment(string $line): string
    {
        return preg_replace('/(^|\s)#.*$/', '', $line) ?? $line;
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
