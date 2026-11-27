<?php

namespace Innoboxrr\LarapackGenerator\Support;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Lo que conecta la guía de LaraPack con las herramientas de los agentes, en
 * los archivos que esas herramientas leen por su cuenta.
 *
 * Los dos archivos son del proyecto, no del paquete: un `.claude/settings.json`
 * suele tener permisos y otros hooks, y un `AGENTS.md`, las reglas de la casa.
 * Por eso aquí nada se sobrescribe: se fusiona lo que falta y se deja el resto
 * como estaba, y reejecutarlo no duplica nada.
 */
final class AgentHooks
{
    public const SETTINGS = '.claude/settings.json';

    public const AGENTS = 'AGENTS.md';

    /**
     * En mayúsculas para que no se confundan con un comando `larapack:…` al
     * buscar los que menciona el texto.
     */
    public const BEGIN = '<!-- LARAPACK:BEGIN -->';

    public const END = '<!-- LARAPACK:END -->';

    private const BLOCK_PATH = '/../../skill/agents-block.md';

    /**
     * La opción de `larapack:guard` que distingue el hook de cada evento: es
     * como se reconoce uno ya instalado.
     */
    private const FLAGS = ['PostToolUse' => null, 'Stop' => '--stop', 'PreToolUse' => '--pre'];

    /**
     * Fusiona los hooks de LaraPack en `.claude/settings.json`.
     *
     * - PostToolUse sobre Write|Edit|MultiEdit: `larapack:guard` revisa el
     *   archivo recién editado y devuelve al modelo lo que se sale del contrato.
     * - Stop: `larapack:guard --stop` corre el Verifier entero y no deja dar la
     *   tarea por terminada con errores. Respeta `stop_hook_active`, así que no
     *   retiene al agente dos veces seguidas por lo mismo.
     * - PreToolUse sobre Write|Edit|MultiEdit|Bash: `larapack:guard --pre`
     *   impide que el agente apruebe una excepción #[HandRolled], editando
     *   `.larapack/hand-rolled.lock` o corriendo `larapack:hand-rolled
     *   approve`. El PostToolUse también lo bloquea, pero cuando ya está
     *   escrito; y de una orden de Bash no se entera, porque su matcher no la
     *   incluye.
     *
     * Un hook ya presente se reconoce por el comando y se deja como está, aunque
     * se haya cambiado a mano: quien lo cambió sabrá por qué.
     *
     * @return array{target: string, added: array<int, string>, present: array<int, string>}
     */
    public static function installSettings(string $root): array
    {
        $target = rtrim($root, '/').'/'.self::SETTINGS;
        $settings = self::readSettings($target);

        if (! isset($settings->hooks)) {
            $settings->hooks = new stdClass;
        }

        if (! $settings->hooks instanceof stdClass) {
            throw new RuntimeException("{$target}: `hooks` no es un objeto. Corrígelo a mano; no se toca.");
        }

        $added = [];
        $present = [];

        foreach (self::hooks($root) as $event => $group) {
            $groups = $settings->hooks->{$event} ?? [];

            if (! is_array($groups)) {
                throw new RuntimeException("{$target}: `hooks.{$event}` no es una lista. Corrígelo a mano; no se toca.");
            }

            if (self::hasGuard($groups, self::FLAGS[$event])) {
                $present[] = $event;

                continue;
            }

            $groups[] = $group;
            $settings->hooks->{$event} = $groups;
            $added[] = $event;
        }

        // Sin cambios no se reescribe: reformatear el archivo de otro sólo
        // para dejarlo igual es ruido en su diff.
        if ($added !== []) {
            // Con el `false` de json_encode pegado a "\n" el archivo se
            // quedaba vacío: un `1e999` en el settings (que json_decode lee
            // como INF) borraba los permisos y los hooks del proyecto.
            try {
                $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("{$target}: no se puede volver a escribir sin perder datos ({$e->getMessage()}). Añade los hooks a mano; no se toca.");
            }

            self::write($root, $target, $json."\n");
        }

        return ['target' => $target, 'added' => $added, 'present' => $present];
    }

    /**
     * Los grupos de hooks que LaraPack añade, por evento.
     *
     * El comando pasa por `$CLAUDE_PROJECT_DIR` porque el hook se ejecuta en el
     * directorio en el que esté la sesión, que no tiene por qué ser la raíz.
     *
     * Antes de llamar a PHP comprueba que haya algo que llamar: en un paquete
     * recién creado con larapack:new, hasta el `composer install` no existe
     * vendor/bin/builder, y una aplicación sin vendor/ no arranca Artisan. Sin
     * esa comprobación cada edición acabaría en un error para el usuario. Se
     * hace con `[ ! -f … ] || php …` y no con `|| true`, que se tragaría
     * también el código 2 de la guardia. Es sintaxis POSIX: Claude Code
     * ejecuta los hooks con bash, también en Windows (Git Bash).
     *
     * @return array<string, stdClass>
     */
    public static function hooks(string $root): array
    {
        [$required, $php] = is_file(rtrim($root, '/').'/artisan')
            ? ['"$CLAUDE_PROJECT_DIR/vendor/autoload.php"', 'php "$CLAUDE_PROJECT_DIR/artisan"']
            : ['"$CLAUDE_PROJECT_DIR/vendor/bin/builder"', 'php "$CLAUDE_PROJECT_DIR/vendor/bin/builder"'];
        $binary = "[ ! -f {$required} ] || {$php}";

        return [
            'PostToolUse' => (object) [
                'matcher' => 'Write|Edit|MultiEdit',
                'hooks' => [(object) [
                    'type' => 'command',
                    'command' => "{$binary} larapack:guard",
                    'timeout' => 30,
                    'statusMessage' => 'LaraPack: comprobando el contrato…',
                ]],
            ],
            'Stop' => (object) [
                'hooks' => [(object) [
                    'type' => 'command',
                    'command' => "{$binary} larapack:guard --stop",
                    'timeout' => 120,
                    'statusMessage' => 'LaraPack: verificando el proyecto…',
                ]],
            ],
            'PreToolUse' => (object) [
                'matcher' => 'Write|Edit|MultiEdit|Bash',
                'hooks' => [(object) [
                    'type' => 'command',
                    'command' => self::preCommand($required, $php),
                    'timeout' => 30,
                ]],
            ],
        ];
    }

    /**
     * El hook de antes de la herramienta corre en cada orden de Bash, y
     * arrancar PHP —en una aplicación, Laravel entero— en cada una se nota.
     * Por eso la shell filtra primero: sólo llama a PHP si la entrada contiene
     * `approve`, `revoke` o `hand-rolled` (ésta sin distinguir mayúsculas). Es un
     * filtro que sólo deja pasar de más: lo que la guardia bloquea siempre
     * contiene alguna de esas palabras, porque el comando sólo acepta esas
     * acciones escritas así y el candado se llama así.
     *
     * Las busca también en la entrada sin comillas ni barras invertidas
     * (`bare`): la shell quita las comillas de en medio de una palabra, y
     * `appr''ove` o `hand"-"rolled` son las mismas palabras. Y deja pasar
     * siempre una barra invertida seguida de salto de línea (en el JSON,
     * `\\\n`): la continuación de línea puede partir una palabra por
     * cualquier sitio, y quitarla aquí con la shell no es fiable.
     *
     * La entrada le llega a PHP por una tubería, y el lado que lee puede
     * cerrarla sin haber leído. Antes, la comprobación de `$required` iba
     * dentro de la tubería (`printf … | { [ ! -f … ] || php …; }`): sin
     * vendor/, el lado que lee salía en el acto, y si lo hacía antes de que
     * printf escribiera, la shell añadía «printf: write error: Broken pipe» a
     * la salida del hook. Era una carrera con una orden corta y una certeza con
     * una entrada mayor que el búfer de la tubería. Ahora la comprobación va
     * delante (`a || b | c` es `a || (b | c)`): sin `$required` no hay
     * tubería. Y el error de printf va a /dev/null: el único que puede tener
     * aquí es esa tubería cerrada —un PHP que salió sin leer, por ejemplo
     * porque la aplicación no arranca—, y lo que cuenta entonces es lo que
     * dice PHP, con su código de salida, que es el de la tubería.
     */
    private static function preCommand(string $required, string $php): string
    {
        $filter = <<<'SH'
        input=$(cat); bare=$(printf '%s' "$input" | tr -d '\\"'"'"); case "$input $bare" in *approve*|*revoke*|*[Hh][Aa][Nn][Dd]-[Rr][Oo][Ll][Ll][Ee][Dd]*|*'\\\'[nr]*)
        SH;

        return $filter." [ ! -f {$required} ] || printf '%s' \"\$input\" 2>/dev/null | {$php} larapack:guard --pre;; esac";
    }

    /**
     * Escribe o actualiza el bloque de LaraPack en `AGENTS.md`.
     *
     * Codex y otros agentes no leen `.claude/skills/`; leen `AGENTS.md`. Lo
     * que hay entre los marcadores es de LaraPack y se reescribe; lo de fuera
     * es del proyecto y no se toca. Sin marcadores, el bloque va al final.
     *
     * @return array{target: string, action: string} action: created|updated|appended|unchanged
     */
    public static function installAgentsBlock(string $root, string $skillPath = Skill::DEFAULT_TARGET, string $target = self::AGENTS): array
    {
        $absolute = rtrim($root, '/').'/'.ltrim($target, '/');
        $block = self::BEGIN."\n".self::agentsBlock($skillPath).self::END."\n";

        if (! is_file($absolute)) {
            self::write($root, $absolute, $block);

            return ['target' => $absolute, 'action' => 'created'];
        }

        $current = (string) file_get_contents($absolute);
        self::assertMarkers($absolute, $current);

        $begin = strpos($current, self::BEGIN);
        $end = $begin === false ? false : strpos($current, self::END, $begin);

        if ($begin === false || $end === false) {
            $separator = $current === '' ? '' : (str_ends_with($current, "\n") ? "\n" : "\n\n");
            self::write($root, $absolute, $current.$separator.$block);

            return ['target' => $absolute, 'action' => 'appended'];
        }

        // Lo que sigue al marcador de cierre, sin su salto de línea: el bloque
        // ya trae el suyo.
        $after = substr($current, $end + strlen(self::END));
        $after = str_starts_with($after, "\n") ? substr($after, 1) : $after;
        $updated = substr($current, 0, $begin).$block.$after;

        if ($updated === $current) {
            return ['target' => $absolute, 'action' => 'unchanged'];
        }

        self::write($root, $absolute, $updated);

        return ['target' => $absolute, 'action' => 'updated'];
    }

    /**
     * Si `--path` apunta al mismo `AGENTS.md`: entonces el skill entero ya es
     * ese archivo, y añadirle el bloque lo duplicaría y le haría decir «lee
     * `AGENTS.md`» de sí mismo.
     */
    public static function isAgentsFile(string $root, string $path, string $target = self::AGENTS): bool
    {
        $path = self::resolve($root, $path);
        $target = self::resolve($root, $target);
        $realPath = realpath($path);
        $realTarget = realpath($target);

        if ($realPath !== false && $realTarget !== false) {
            return $realPath === $realTarget;
        }

        // Sin los dos en disco no hay realpath que comparar. Sin distinguir
        // mayúsculas: en Windows y macOS `agents.md` es el mismo archivo.
        return strcasecmp($path, $target) === 0;
    }

    /**
     * El texto del bloque, con la ruta en la que se instaló el skill.
     */
    public static function agentsBlock(string $skillPath = Skill::DEFAULT_TARGET): string
    {
        $path = realpath(__DIR__.self::BLOCK_PATH);
        $contents = $path === false ? false : file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('No se encontró skill/agents-block.md en el paquete.');
        }

        return str_replace('{{skill}}', str_replace('\\', '/', $skillPath), str_replace("\r\n", "\n", $contents));
    }

    /**
     * Un marcador suelto —un BEGIN sin su END, editado a mano— no se
     * arregla adivinando: añadir otro bloque haría que la siguiente
     * ejecución reemplazara todo lo que hay entre el BEGIN huérfano y el END
     * nuevo, que es texto del proyecto.
     */
    private static function assertMarkers(string $file, string $contents): void
    {
        $begins = substr_count($contents, self::BEGIN);
        $ends = substr_count($contents, self::END);
        $begin = strpos($contents, self::BEGIN);
        $end = strpos($contents, self::END);

        if ($begins === 0 && $ends === 0) {
            return;
        }

        if ($begins !== 1 || $ends !== 1 || $begin === false || $end === false || $end < $begin) {
            throw new RuntimeException(sprintf(
                '%s: los marcadores de LaraPack están descompensados (%d %s, %d %s). Debe haber un único bloque entre %s y %s: corrígelo a mano; no se toca.',
                $file, $begins, self::BEGIN, $ends, self::END, self::BEGIN, self::END
            ));
        }
    }

    /**
     * La ruta absoluta y normalizada, sin `./` ni barras repetidas.
     */
    private static function resolve(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
        $path = $isAbsolute ? $path : rtrim(str_replace('\\', '/', $root), '/').'/'.$path;

        return (string) preg_replace(['#/(\./)+#', '#/{2,}#'], '/', $path);
    }

    /**
     * Si alguno de los grupos ya ejecuta `larapack:guard` con esa opción (o,
     * con null, sin ninguna de las que distinguen los eventos).
     *
     * @param  array<int, mixed>  $groups
     */
    private static function hasGuard(array $groups, ?string $flag): bool
    {
        foreach ($groups as $group) {
            $handlers = is_object($group) && isset($group->hooks) && is_array($group->hooks) ? $group->hooks : [];

            foreach ($handlers as $handler) {
                $command = is_object($handler) && isset($handler->command) && is_string($handler->command) ? $handler->command : '';

                if (! str_contains($command, 'larapack:guard')) {
                    continue;
                }

                $flags = array_filter(self::FLAGS, fn (?string $known): bool => $known !== null && str_contains($command, $known));

                if ($flag === null ? $flags === [] : in_array($flag, $flags, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Se decodifica a objetos y no a arrays: un `{}` vacío del usuario
     * —`"env": {}`— volvería como `[]` y cambiaría de tipo al guardarlo.
     */
    private static function readSettings(string $target): stdClass
    {
        if (! is_file($target)) {
            return new stdClass;
        }

        $raw = (string) file_get_contents($target);

        if (trim($raw) === '') {
            return new stdClass;
        }

        $decoded = json_decode($raw, false);

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException("{$target} no es un objeto JSON válido. Corrígelo a mano; no se sobrescribe.");
        }

        return $decoded;
    }

    private static function write(string $root, string $file, string $contents): void
    {
        self::assertInside($root, $file);

        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
    }

    /**
     * Que escribir `$file` no acabe fuera del proyecto por un enlace
     * simbólico.
     *
     * Un repositorio puede traer `.claude/settings.json` —o `.claude`, o
     * `AGENTS.md`— como enlace. Si apuntaba al `~/.claude/settings.json` de
     * quien lo clona, `larapack:skill --hooks` añadía los hooks a su
     * configuración global: desde entonces, cada edición en cualquier proyecto
     * ejecutaba su `artisan` o su `vendor/bin/builder`.
     *
     * Se resuelve el camino real del archivo, o del primer antecesor que
     * existe, y tiene que quedar dentro de la raíz; un enlace roto en el
     * camino cuenta como fuera. Un enlace que se queda dentro (AGENTS.md →
     * CLAUDE.md) vale, y una ruta que ya sale del proyecto por escrito
     * (`--path=../x`) también: eso lo pidió quien ejecuta el comando.
     */
    public static function assertInside(string $root, string $file): void
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return;
        }

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');

        if (! self::within(self::lexical(str_replace('\\', '/', $file)), $realRoot)
            && ! self::within(self::lexical(str_replace('\\', '/', $file)), rtrim(self::lexical(str_replace('\\', '/', $root)), '/'))) {
            return;
        }

        $path = $file;

        while (! file_exists($path)) {
            if (is_link($path)) {
                throw new RuntimeException("{$file}: {$path} es un enlace simbólico roto; no se escribe a través de él. Hazlo a mano si es lo que quieres.");
            }

            $parent = dirname($path);

            if ($parent === $path) {
                return;
            }

            $path = $parent;
        }

        $real = str_replace('\\', '/', (string) realpath($path));

        if (! self::within($real, $realRoot)) {
            throw new RuntimeException("{$file} lleva fuera del proyecto, a {$real}: no se escribe a través de un enlace simbólico. Hazlo a mano si es lo que quieres.");
        }
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    /**
     * La ruta con `.` y `..` resueltos sobre el texto, sin mirar el disco.
     */
    private static function lexical(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $index => $part) {
            if ($part === '..' && $parts !== [] && end($parts) !== '' && end($parts) !== '..') {
                array_pop($parts);
            } elseif ($part !== '.' && ($part !== '' || $index === 0)) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
