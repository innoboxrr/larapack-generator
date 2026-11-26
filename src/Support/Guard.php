<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * La guía de LaraPack convertida en algo que se ejecuta.
 *
 * El skill es texto, y un agente olvida el texto a mitad de una sesión larga:
 * en school-management los controladores generados respetaron el patrón
 * `return $request->handle();`, pero los escritos a mano —asistentes,
 * escritorios, rutas públicas, webhooks, tokens— acabaron con validación,
 * autorización y consultas dentro. `larapack:verify` no lo vio porque nadie lo
 * corrió en ese momento.
 *
 * Esto es lo que un hook de Claude Code consulta después de cada edición: si
 * el archivo tocado es parte de la arquitectura, se le pasan las
 * comprobaciones del Verifier, y lo que encuentren vuelve al agente en el acto,
 * cuando aún tiene el archivo delante. No reimplementa ningún check: filtra los
 * hallazgos del Verifier por archivo, así que un check nuevo se aprovecha aquí
 * sin tocar nada.
 */
final class Guard
{
    /**
     * Lo que se recuerda cada vez que algo se bloquea. Es la regla entera, en
     * corto: un agente que la tiene delante no necesita releer el skill.
     */
    public const RULE = 'La regla: la arquitectura se declara en laraimport.json y se genera; '
        .'el controlador sólo hace `return $request->handle();`; la autorización, la validación '
        .'y la lógica van en el FormRequest (authorize(), rules(), handle()), que delega en la '
        .'operación del modelo.';

    /**
     * Lo que se dice cuando un agente toca la aprobación de una excepción.
     */
    public const LOCK_RULE = 'Sólo el dueño del proyecto aprueba excepciones #[HandRolled]: él corre '
        .'`larapack:hand-rolled approve`, revisa '.HandRolledLock::PATH.' y lo comitea. Tú no editas ese '
        .'archivo ni corres `approve` o `revoke`. Si una acción de verdad no puede ser '
        .'`return $request->handle();`, dilo en tu respuesta y deja que el dueño decida.';

    /**
     * Los separadores de órdenes de la shell, y los de redirección: lo que
     * los sigue ya no son argumentos de la orden anterior.
     */
    private const SHELL_SEPARATORS = [';', '&', '|', "\n", '(', ')', '`', '<', '>'];

    /**
     * Las partes de un proyecto Laravel que son arquitectura, aunque el
     * archivo lo haya escrito alguien a mano: es justo ahí donde se pierde la
     * guía, porque el generador no tuvo nada que ver.
     */
    private const WATCHED = [
        '#(^|/)Http/Controllers/.+\.php$#',
        '#(^|/)Http/Requests/.+\.php$#',
        '#(^|/)routes/.+\.php$#',
        '#(^|/)Policies/.+\.php$#',
        '#(^|/)migrations/.+\.php$#',
    ];

    /**
     * Lo que instala composer o npm: código de terceros, aunque tenga
     * controladores y su propio composer.json.
     */
    private const THIRD_PARTY = '#(^|/)(vendor|node_modules)/#';

    /**
     * Lo único que ControllerShape mira: es lo que se comprueba en un
     * proyecto sin manifiesto, porque es lo único de `verify` que no lo
     * necesita.
     */
    private const SHAPE_FILES = '#(^|/)Http/(Controllers|Requests)/.+\.php$#';

    /**
     * Los stubs de los huecos: lo generado que se rellena a mano. Editarlos es
     * el trabajo normal, y pasarles el Verifier en cada edición sólo añadiría
     * espera. Las Policies están en WATCHED de todos modos.
     */
    private const HOLLOW_STUBS = [
        'ModelTraits/',
        'Filters/ManagedFilter',
        'Resource/',
        'Events/Listeners/',
        'Observer/',
        'Factory/',
        'Test/',
    ];

    /**
     * Las rutas de los archivos que tocó la herramienta, en absoluto.
     *
     * Write y Edit traen `tool_input.file_path`; una edición múltiple puede
     * traer además una lista `edits` con su propio `file_path`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function touchedFiles(array $payload): array
    {
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
        $candidates = [$input['file_path'] ?? null];

        foreach (is_array($input['edits'] ?? null) ? $input['edits'] : [] as $edit) {
            $candidates[] = is_array($edit) ? ($edit['file_path'] ?? null) : null;
        }

        $base = self::workingDirectory($payload);
        $files = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $files[] = self::absolute($candidate, $base);
        }

        return array_values(array_unique($files));
    }

    /**
     * Los archivos tocados que son el candado de las excepciones, de
     * cualquier proyecto. Sin distinguir mayúsculas: en Windows y macOS
     * `Hand-Rolled.lock` es el mismo archivo.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function touchedLocks(array $payload): array
    {
        return array_values(array_filter(
            self::touchedFiles($payload),
            fn (string $file): bool => preg_match('#(^|/)\.larapack/hand-rolled\.lock$#i', $file) === 1
        ));
    }

    /**
     * Si la orden de Bash ejecuta `larapack:hand-rolled approve` o `revoke`.
     *
     * Se lee como la lee la shell, sin ejecutar nada: se parte en órdenes por
     * sus separadores, respetando las comillas, y en cada una se busca el
     * comando —también por el alias `make:` del binario y por las abreviaturas
     * que Symfony admite, como `larapack:hand`— seguido de `approve` o
     * `revoke` como palabra entera. Lo que la shell va a ejecutar como orden
     * —el argumento de `-c` en `bash -c "…"`, lo que sigue a `eval`— se lee
     * también como orden; un texto entre comillas cualquiera (`grep "…"`), no.
     *
     * No busca el subcomando en una posición concreta: una opción con su
     * valor delante (`--root packages/x`) lo movería. Cualquier `approve` o
     * `revoke` detrás del comando cuenta; las únicas órdenes que eso bloquea
     * de más —`list approve`— fallan igual al ejecutarse.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function approvesHandRolled(array $payload): bool
    {
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
        $command = $input['command'] ?? null;

        return is_string($command) && self::shellApproves($command, 0);
    }

    private static function shellApproves(string $command, int $depth): bool
    {
        foreach (self::shellCommands($command) as $words) {
            $invoked = false;
            $eval = ($words[0] ?? null) === 'eval';

            foreach ($words as $index => $word) {
                if ($invoked && in_array($word, ['approve', 'revoke'], true)) {
                    return true;
                }

                $invoked = $invoked || self::namesHandRolled($word);
                $nested = $eval || preg_match('/^-[a-z]*c$/i', $words[$index - 1] ?? '') === 1;

                if ($nested && $depth < 3 && self::shellApproves($word, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Si la palabra es un nombre con el que Symfony encontraría
     * `larapack:hand-rolled`: el espacio, prefijo de `larapack` o del alias
     * `make`; el nombre, prefijo de `hand-rolled`.
     */
    private static function namesHandRolled(string $word): bool
    {
        if (preg_match('/^([a-z]+):([a-z-]+)$/i', $word, $parts) !== 1) {
            return false;
        }

        $namespace = strtolower($parts[1]);
        $name = strtolower($parts[2]);

        return (str_starts_with('larapack', $namespace) || str_starts_with('make', $namespace))
            && str_starts_with('hand-rolled', $name);
    }

    /**
     * Las órdenes de una línea de shell, cada una como sus palabras ya sin
     * comillas.
     *
     * @return list<list<string>>
     */
    private static function shellCommands(string $line): array
    {
        $commands = [];
        $words = [];
        $word = null;
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote === "'") {
                $quote = $char === "'" ? null : $quote;
                $word .= $char === "'" ? '' : $char;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length && ($quote === null || in_array($line[$i + 1], ['"', '\\', '$', '`'], true))) {
                $word .= $line[++$i];

                continue;
            }

            if ($quote === '"') {
                $quote = $char === '"' ? null : $quote;
                $word .= $char === '"' ? '' : $char;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $word ??= '';

                continue;
            }

            if (ctype_space($char) || in_array($char, self::SHELL_SEPARATORS, true)) {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }

                if ($char !== ' ' && $char !== "\t" && $words !== []) {
                    $commands[] = $words;
                    $words = [];
                }

                continue;
            }

            $word .= $char;
        }

        if ($word !== null) {
            $words[] = $word;
        }

        if ($words !== []) {
            $commands[] = $words;
        }

        return $commands;
    }

    /**
     * El mensaje de bloqueo por tocar la aprobación de una excepción.
     *
     * @param  array<int, string>  $locks  los candados tocados
     * @param  bool  $done  si ya ocurrió (PostToolUse): hay que deshacerlo
     */
    public static function lockMessage(array $locks, bool $command, bool $done): string
    {
        $lines = [];

        foreach ($locks as $lock) {
            $lines[] = "LaraPack: {$lock} es la aprobación del dueño del proyecto, no un archivo que se edite.";
        }

        if ($command) {
            $lines[] = 'LaraPack: `larapack:hand-rolled approve` y `revoke` los corre el dueño del proyecto, no un agente.';
        }

        $lines[] = self::LOCK_RULE;

        if ($done) {
            $lines[] = 'Ya está hecho: deshazlo (git checkout -- '.HandRolledLock::PATH.', o como estaba) antes de seguir.';
        }

        return implode("\n", $lines);
    }

    /**
     * Desde dónde trabaja la sesión: el `cwd` del hook, o el del proceso.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function workingDirectory(array $payload): string
    {
        $cwd = $payload['cwd'] ?? null;

        if (is_string($cwd) && $cwd !== '' && is_dir($cwd)) {
            return self::normalise((string) realpath($cwd));
        }

        return self::normalise((string) getcwd());
    }

    /**
     * El proyecto al que pertenece un archivo: el directorio más cercano, hacia
     * arriba, con `composer.json` o con manifiesto.
     *
     * No vale la raíz de la sesión. En un monorepo como school-management cada
     * paquete de `packages/` tiene su propio manifiesto, y un controlador de un
     * paquete se verifica contra el del paquete, no contra el de la aplicación.
     */
    public static function projectOf(string $path): ?string
    {
        $directory = is_dir($path) ? $path : dirname($path);

        while (true) {
            if (is_file($directory.'/composer.json') || is_file($directory.'/.larapack/manifest.json')) {
                return self::normalise((string) (realpath($directory) ?: $directory));
            }

            $parent = dirname($directory);

            // dirname() de una raíz se devuelve a sí mismo.
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    /**
     * Si el archivo es de un tercero: está bajo el `vendor/` de un proyecto
     * de composer o bajo un `node_modules/`.
     *
     * Se mira sobre la ruta absoluta, antes de buscar el proyecto, y no sobre
     * la relativa a él: un paquete de vendor/ tiene su propio composer.json,
     * así que relativo a su proyecto ya no hay ningún `vendor/` en la ruta.
     * Tampoco sobre el `cwd` de la sesión, que sigue los `cd` del agente y
     * puede estar dentro del paquete. Un `vendor` cuenta sólo si el
     * directorio que lo contiene es un proyecto de composer: un proyecto que
     * viva él mismo bajo una carpeta llamada `vendor` no se excluye entero.
     */
    public static function isThirdParty(string $file): bool
    {
        $directory = dirname(self::normalise($file));

        while (true) {
            $parent = dirname($directory);

            // dirname() de una raíz se devuelve a sí mismo.
            if ($parent === $directory) {
                return false;
            }

            $name = basename($directory);

            if ($name === 'node_modules' || ($name === 'vendor' && is_file($parent.'/composer.json'))) {
                return true;
            }

            $directory = $parent;
        }
    }

    /**
     * Lo que se comprueba en un proyecto sin manifiesto: sólo los
     * controladores y las requests, que es lo que ControllerShape recorre.
     *
     * Es deliberado y no un descuido: `larapack:verify` corre ControllerShape
     * también sin manifiesto —un proyecto sin nada generado tiene
     * controladores, y es donde más se pierde la regla—, y la guardia no
     * puede dejar pasar en la edición lo que verify rechaza después. El resto
     * de los checks sí necesita el manifiesto y sin él sólo diría que está
     * vacío.
     */
    public static function isShapeFile(string $relative): bool
    {
        return preg_match(self::SHAPE_FILES, $relative) === 1;
    }

    /**
     * La ruta relativa a la raíz, o null si el archivo cae fuera.
     */
    public static function relative(string $root, string $file): ?string
    {
        $root = rtrim(self::normalise($root), '/');
        $file = self::normalise($file);

        return str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : null;
    }

    /**
     * Si el archivo es arquitectura: una de las carpetas vigiladas, o algo que
     * el generador escribió y que no es un hueco.
     */
    public static function watches(string $relative, Manifest $manifest): bool
    {
        // Lo de terceros no es de este proyecto, aunque tenga controladores.
        // Con --root la ruta relativa todavía lo dice; sin él, lo dice
        // isThirdParty(), antes de buscar el proyecto.
        if (preg_match(self::THIRD_PARTY, $relative)) {
            return false;
        }

        foreach (self::WATCHED as $pattern) {
            if (preg_match($pattern, $relative)) {
                return true;
            }
        }

        $stub = $manifest->stubOf($relative);

        if ($stub === null) {
            return false;
        }

        foreach (self::HOLLOW_STUBS as $hollow) {
            if (str_starts_with($stub, $hollow)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un controlador que el generador no escribió.
     *
     * Aunque ControllerShape no le encuentre nada, es el caso que más necesita
     * que se le recuerde la regla: es donde se perdió en school-management. El
     * `Controller.php` base no es un endpoint.
     */
    public static function isHandWrittenController(string $relative, Manifest $manifest): bool
    {
        return preg_match('#(^|/)Http/Controllers/.+\.php$#', $relative) === 1
            && basename($relative) !== 'Controller.php'
            && $manifest->stubOf($relative) === null;
    }

    /**
     * El recordatorio para un archivo de Http/Controllers escrito a mano, o
     * null si no le toca ninguno.
     *
     * Un trait no es un controlador, y decirle que «cada acción es
     * `return $request->handle();`» no tiene sentido: lo que se le recuerda es
     * que su sitio no es ése.
     */
    public static function reminderFor(string $relative, string $file, Manifest $manifest): ?string
    {
        if (! self::isHandWrittenController($relative, $manifest)) {
            return null;
        }

        $kinds = is_file($file) ? array_column(PhpOutline::ofFile($file)->classes(), 'kind') : [];

        return $kinds !== [] && array_diff($kinds, ['trait']) === []
            ? self::traitReminder($relative)
            : self::controllerReminder($relative);
    }

    /**
     * Los hallazgos del Verifier sobre el proyecto, ejecutado contra su raíz.
     *
     * @param  array<int, string>  $only  sólo estas comprobaciones; vacío, todas
     * @return array<int, array<string, mixed>>
     */
    public static function findings(string $root, array $only = []): array
    {
        return ProjectRoot::using($root, fn (): array => (new Verifier(new Manifest($root)))->run($only));
    }

    /**
     * Los errores que apuntan a un archivo concreto.
     *
     * Se filtra por el campo `file` del hallazgo, sin mirar de qué check viene:
     * así cualquier comprobación que el Verifier gane después se aplica aquí
     * en cuanto exista.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function errorsFor(array $findings, ?string $relative = null): array
    {
        return array_values(array_filter($findings, function (array $finding) use ($relative): bool {
            if (($finding['level'] ?? null) !== Verifier::ERROR) {
                return false;
            }

            if ($relative === null) {
                return true;
            }

            $file = $finding['file'] ?? null;

            return is_string($file) && preg_replace('#^\./#', '', self::normalise($file)) === $relative;
        }));
    }

    /**
     * Cómo se llama a LaraPack en ese proyecto: una aplicación tiene Artisan;
     * un paquete, el binario del generador.
     */
    public static function commandPrefix(string $root): string
    {
        return is_file($root.'/artisan') ? 'php artisan' : 'php vendor/bin/builder';
    }

    /**
     * El mensaje que vuelve al agente: qué está mal, la regla y qué correr.
     *
     * Corto a propósito. Llega como respuesta a una herramienta en mitad de
     * una tarea; si es largo, se lee en diagonal.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public static function message(string $headline, array $errors, string $root, int $limit = 8): string
    {
        $lines = [$headline];

        foreach (array_slice($errors, 0, $limit) as $error) {
            $lines[] = sprintf(
                '  - [%s] %s%s',
                $error['check'] ?? 'error',
                isset($error['file']) && is_string($error['file']) ? "{$error['file']}: " : '',
                $error['message'] ?? ''
            );
        }

        if (count($errors) > $limit) {
            $lines[] = sprintf('  … y %d más.', count($errors) - $limit);
        }

        $lines[] = self::RULE;
        $lines[] = 'Corrígelo y comprueba con: '.self::commandPrefix($root).' larapack:verify --format=json';

        return implode("\n", $lines);
    }

    /**
     * El recordatorio para un controlador escrito a mano que, por ahora, no
     * tiene errores.
     *
     * Se dice como exigencia y no como afirmación: que no haya errores no
     * significa que el archivo cumpla —ControllerShape no ve lo que aún no
     * está escrito—, y un «sigue el patrón» lo leería el agente como un visto
     * bueno.
     */
    public static function controllerReminder(string $relative): string
    {
        return "LaraPack: {$relative} es un controlador escrito a mano y DEBE seguir el mismo patrón que los generados: "
            .'cada acción es `return $request->handle();` y nada más. Si alguna acción valida, autoriza, consulta o arma '
            .'la respuesta, muévelo a un FormRequest con authorize(), rules() y handle(), que delega en la operación del '
            .'modelo o en una clase de soporte. Un trait de autorización compartido va en Http/Requests/Concerns, no en '
            .'Http/Controllers.';
    }

    /**
     * El recordatorio para un trait escrito a mano dentro de Http/Controllers.
     */
    public static function traitReminder(string $relative): string
    {
        return "LaraPack: {$relative} es un trait dentro de Http/Controllers, y ése no es su sitio: lo que comparten varias "
            .'acciones —autorización, validación, consultas— va en una request base o en un trait de Http/Requests/Concerns, '
            .'o en una clase de soporte, y los controladores se quedan en `return $request->handle();`.';
    }

    /**
     * Apunta que esta sesión tocó el archivo, para que el hook de fin de turno
     * sepa qué es suyo aunque lo haya confirmado después.
     *
     * Va al directorio temporal y no al proyecto: es estado de una sesión, no
     * algo que versionar. Si no se puede escribir, se sigue sin él: el hook de
     * fin de turno todavía tiene git.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function remember(array $payload, string $file): void
    {
        $state = self::stateFile($payload);

        if ($state === null || in_array($file, self::recorded($payload) ?? [], true)) {
            return;
        }

        $directory = dirname($state);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return;
        }

        @file_put_contents($state, $file."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Los archivos del proyecto que son de esta sesión, relativos a su raíz:
     * los que pasaron por el hook de edición con este `session_id` y los que
     * git ve cambiados o nuevos sin confirmar.
     *
     * Las dos fuentes se suman porque cada una ve lo que la otra no: la de la
     * sesión sigue viendo lo que ya se confirmó; git ve lo que se editó por
     * Bash, que no pasa por el hook. Lo que git ve es del árbol de trabajo,
     * no de la sesión: dos consolas en el mismo checkout se ven sus cambios
     * sin confirmar; en worktrees separados, no.
     *
     * Null si no hay forma de saberlo —ni registro ni git—: entonces cuenta
     * el proyecto entero, como antes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>|null
     */
    public static function sessionFiles(array $payload, string $root): ?array
    {
        $recorded = self::recorded($payload);
        $changed = self::gitChanges($root);

        if ($recorded === null && $changed === null) {
            return null;
        }

        $files = [];

        foreach ([...($recorded ?? []), ...($changed ?? [])] as $file) {
            $relative = self::relative($root, $file);

            if ($relative !== null) {
                $files[] = $relative;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Separa los errores en los que retienen el turno —los de archivos de la
     * sesión— y los que sólo se avisan.
     *
     * Un error sin archivo (de un modelo, del manifiesto) es de la sesión si
     * la sesión tocó algo del proyecto: no hay forma de saber más, y si no
     * tocó nada no es suyo.
     *
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<int, string>|null  $sessionFiles  null: no se sabe, todo retiene
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public static function partition(array $errors, ?array $sessionFiles): array
    {
        if ($sessionFiles === null) {
            return [$errors, []];
        }

        $blocking = [];
        $others = [];

        foreach ($errors as $error) {
            $file = $error['file'] ?? null;
            $mine = is_string($file)
                ? in_array(preg_replace('#^\./#', '', self::normalise($file)), $sessionFiles, true)
                : $sessionFiles !== [];

            if ($mine) {
                $blocking[] = $error;
            } else {
                $others[] = $error;
            }
        }

        return [$blocking, $others];
    }

    /**
     * Lo que la sesión apuntó con remember(), en absoluto, o null si no hay
     * sesión o no apuntó nada.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>|null
     */
    private static function recorded(array $payload): ?array
    {
        $state = self::stateFile($payload);
        $contents = $state !== null && is_file($state) ? file_get_contents($state) : false;

        if ($contents === false) {
            return null;
        }

        return array_values(array_filter(explode("\n", $contents), fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stateFile(array $payload): ?string
    {
        $session = $payload['session_id'] ?? null;

        if (! is_string($session) || trim($session) === '') {
            return null;
        }

        return self::normalise(sys_get_temp_dir()).'/larapack-guard/'.sha1($session).'.txt';
    }

    /**
     * Los archivos del proyecto con cambios sin confirmar, nuevos incluidos,
     * en absoluto; null si el proyecto no está en un repositorio git o git
     * no responde.
     *
     * @return array<int, string>|null
     */
    private static function gitChanges(string $root): ?array
    {
        $top = self::git($root, ['rev-parse', '--show-toplevel']);

        if ($top === null || trim($top) === '') {
            return null;
        }

        // Con -z las rutas van tal cual, sin comillas, y siempre relativas a
        // la raíz del repositorio; `-- .` las limita al proyecto.
        $status = self::git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--', '.']);

        if ($status === null) {
            return null;
        }

        $top = rtrim(self::normalise(trim($top)), '/');
        $entries = explode("\0", $status);
        $files = [];

        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];

            if (strlen($entry) < 4) {
                continue;
            }

            $files[] = $top.'/'.substr($entry, 3);

            // Un renombrado o una copia traen detrás la ruta de origen, que
            // ya no es un archivo del árbol.
            if (in_array($entry[0], ['R', 'C'], true)) {
                $i++;
            }
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $arguments
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

        return proc_close($process) === 0 && is_string($output) ? $output : null;
    }

    private static function absolute(string $path, string $base): string
    {
        $path = self::normalise($path);

        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
        $absolute = $isAbsolute ? $path : rtrim($base, '/').'/'.$path;

        // realpath resuelve enlaces y `..`; si el archivo ya no existe, se usa
        // tal cual y simplemente no caerá en ningún proyecto.
        $resolved = realpath($absolute);

        return $resolved === false ? $absolute : self::normalise($resolved);
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
