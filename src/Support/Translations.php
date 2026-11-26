<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Los archivos de idioma de lo generado.
 *
 * El código generado escribe sus textos como claves en inglés —`t('Create')` en
 * el front, `__('Show')` en Laravel— y nadie las traducía: la pantalla salía en
 * inglés, o mezclada con los textos en español de rutas, migas y datatables.
 *
 * Esto recoge las claves que usan los archivos y las suma al JSON de cada
 * idioma sin tocar lo que ya haya: una traducción escrita a mano no se pierde al
 * regenerar. Las que LaraPack conoce llegan traducidas desde Stubs/Locales.
 * Las del dominio —el nombre del modelo, sus campos— no las puede saber:
 *
 * - en el front quedan vacías, para que se vea qué falta; innoboxrr-i18n
 *   muestra la clave mientras tanto;
 * - en Laravel no se escriben, porque su traductor pintaría el hueco.
 */
final class Translations
{
    public const FRONTEND = 't';

    public const BACKEND = '__';

    /**
     * @param  string  $directory  donde viven `<idioma>.json`
     * @param  array<int, string>  $files  archivos de los que salen las claves
     * @param  array<int, string>  $languages
     */
    public static function sync(string $directory, array $files, string $function, array $languages): void
    {
        $keys = self::keysIn($files, $function);

        if ($keys === []) {
            return;
        }

        foreach ($languages as $language) {
            self::syncLanguage($directory.'/'.$language.'.json', $language, $keys, $function);
        }
    }

    /**
     * Los archivos de código bajo un directorio, en un orden estable: las
     * claves nuevas se añaden en el orden en que aparecen, y ese orden no
     * puede depender de cómo liste el disco el sistema operativo.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    public static function sourcesIn(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (in_array($file->getExtension(), $extensions, true) && ! str_contains($path, '/locales/')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Las claves literales de `t('…')` o `__('…')`, sin repetir.
     *
     * Solo cuenta una cadena completa como primer argumento: `t('Hello ' + name)`
     * no es una clave, y adivinarla escribiría basura en el archivo.
     *
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    public static function keysIn(array $files, string $function): array
    {
        $pattern = '/(?<![\w$.>:])'.preg_quote($function, '/')
            .'\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*[,)]/s';

        $keys = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            preg_match_all($pattern, (string) file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = $function === self::FRONTEND
                    ? self::unescapeJs($match[2])
                    : self::unescapePhp($match[2], $match[1]);

                if ($key !== null && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * El valor de una cadena JS, como lo verá `t()` al ejecutarse.
     *
     * Antes se quitaba la barra a todo escape: la etiqueta «Dos\nlíneas»,
     * escrita como `t('Dos\nlíneas')`, quedaba en el archivo de idioma como
     * «Dosnlíneas», una clave que nadie pide, y la traducción no se aplicaba
     * nunca. Lo mismo con `\u2028` o con los `\x26` de las plantillas Vue
     * (Literal::vueInterpolation()).
     *
     * Null si no es UTF-8 válido: un archivo del proyecto en Latin-1 no es una
     * clave que se pueda escribir en un JSON.
     */
    public static function unescapeJs(string $raw): ?string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $decoded = preg_replace_callback(
            '/\\\\(?:u\{([0-9A-Fa-f]+)\}|u([dD][89abAB][0-9A-Fa-f]{2})\\\\u([dD][c-fC-F][0-9A-Fa-f]{2})|u([0-9A-Fa-f]{4})|x([0-9A-Fa-f]{2})|(\r\n|[\n\r\x{2028}\x{2029}])|(.))/su',
            function (array $match): string {
                $code = match (true) {
                    $match[1] !== null => hexdec($match[1]),
                    $match[2] !== null => 0x10000 + ((hexdec($match[2]) - 0xD800) << 10) + (hexdec($match[3]) - 0xDC00),
                    $match[4] !== null => hexdec($match[4]),
                    $match[5] !== null => hexdec($match[5]),
                    default => null,
                };

                if ($code !== null) {
                    return (is_int($code) ? mb_chr($code, 'UTF-8') : false) ?: '';
                }

                // Una barra antes de un salto es una continuación de línea:
                // no aporta nada a la cadena.
                if ($match[6] !== null) {
                    return '';
                }

                return match ($match[7]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\f",
                    'v' => "\v",
                    '0' => "\0",
                    default => $match[7] ?? '',
                };
            },
            $raw,
            flags: PREG_UNMATCHED_AS_NULL
        );

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * El valor de una cadena PHP, como lo verá `__()`. En comilla simple sólo
     * `\\` y `\'` son escapes; en comilla doble, una variable la convierte en
     * algo que no es una clave literal.
     */
    public static function unescapePhp(string $raw, string $quote): ?string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        if ($quote === "'") {
            return (string) preg_replace('/\\\\([\\\\\'])/', '$1', $raw);
        }

        if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\$/', $raw) === 1) {
            return null;
        }

        $decoded = preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\}|([nrtvef\\\\$"]))/',
            fn (array $match): string => match (true) {
                $match[1] !== null => chr(octdec($match[1]) & 0xFF),
                $match[2] !== null => chr((int) hexdec($match[2])),
                $match[3] !== null => (string) mb_chr((int) hexdec($match[3]), 'UTF-8'),
                default => ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"][$match[4]] ?? $match[4] ?? '',
            },
            $raw,
            flags: PREG_UNMATCHED_AS_NULL
        );

        return is_string($decoded) && mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private static function syncLanguage(string $path, string $language, array $keys, string $function): void
    {
        $stub = stubs_path("Locales/{$language}.json");
        $exists = is_file($path);

        $current = $exists ? json_decode((string) file_get_contents($path), true) : [];

        // Un archivo que no se puede leer lo ha roto alguien a mano: escribir
        // encima borraría lo que tuviera.
        if (! is_array($current)) {
            Generation::record('preserved', $path, $stub, 'JSON inválido');

            return;
        }

        $dictionary = self::dictionary($language);
        $merged = $current;

        foreach ($keys as $key) {
            // Una traducción vacía es una clave pendiente: se rellena si
            // LaraPack la conoce, y si no se deja como está.
            if (($merged[$key] ?? '') !== '') {
                continue;
            }

            $translation = $language === 'en' ? $key : ($dictionary[$key] ?? '');

            if ($translation === '' && ($function === self::BACKEND || array_key_exists($key, $merged))) {
                continue;
            }

            $merged[$key] = $translation;
        }

        if ($merged === $current) {
            return;
        }

        if (Generation::isDryRun()) {
            Generation::record($exists ? 'overwrite' : 'create', $path, $stub);

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear {$directory}.");
        }

        // Con JSON_THROW_ON_ERROR y no con el `false` de json_encode: pegado a
        // "\n", ese `false` dejaba el archivo vacío y se llevaba todas las
        // traducciones escritas a mano. Bastaba un archivo del proyecto en
        // Latin-1 con un `t('canción')` en el mismo src.
        file_put_contents(
            $path,
            json_encode((object) $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );

        Generation::record($exists ? 'overwrite' : 'create', $path, $stub);
    }

    /**
     * @return array<string, string>
     */
    private static function dictionary(string $language): array
    {
        $path = stubs_path("Locales/{$language}.json");

        if (! is_file($path)) {
            return [];
        }

        $dictionary = json_decode((string) file_get_contents($path), true);

        return is_array($dictionary) ? $dictionary : [];
    }
}
