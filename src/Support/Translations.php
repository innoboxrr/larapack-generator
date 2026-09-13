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
            self::syncLanguage($directory . '/' . $language . '.json', $language, $keys, $function);
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
        $pattern = '/(?<![\w$.>:])' . preg_quote($function, '/')
            . '\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*[,)]/s';

        $keys = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            preg_match_all($pattern, (string) file_get_contents($file), $matches);

            foreach ($matches[2] as $raw) {
                $key = (string) preg_replace('/\\\\(.)/s', '$1', $raw);

                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_map('strval', array_keys($keys));
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

        file_put_contents(
            $path,
            json_encode((object) $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
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
