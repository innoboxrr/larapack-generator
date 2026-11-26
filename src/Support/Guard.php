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
        if (preg_match('#(^|/)(vendor|node_modules)/#', $relative)) {
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
     * Hasta que el Verifier tenga algo que decir de su forma, es el caso que
     * más necesita que se le recuerde la regla: es donde se perdió en
     * school-management. El `Controller.php` base no es un endpoint.
     */
    public static function isHandWrittenController(string $relative, Manifest $manifest): bool
    {
        return preg_match('#(^|/)Http/Controllers/.+\.php$#', $relative) === 1
            && basename($relative) !== 'Controller.php'
            && $manifest->stubOf($relative) === null;
    }

    /**
     * Los hallazgos del Verifier sobre el proyecto, ejecutado contra su raíz.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findings(string $root): array
    {
        return ProjectRoot::using($root, fn (): array => (new Verifier(new Manifest($root)))->run());
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
     * tiene errores. Se dice como un hecho, no como una orden: es contexto que
     * se añade junto al resultado de la herramienta.
     */
    public static function controllerReminder(string $relative): string
    {
        return "LaraPack: {$relative} es un controlador escrito a mano y sigue el mismo patrón que los generados. "
            .'Cada acción es `return $request->handle();` y nada más: la validación, la autorización, las consultas '
            .'y la respuesta van en un FormRequest con authorize(), rules() y handle(), que delega en la operación '
            .'del modelo o en una clase de soporte. Un trait de autorización compartido vive en Http/Requests/Concerns, '
            .'no en Http/Controllers.';
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
