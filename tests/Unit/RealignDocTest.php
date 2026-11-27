<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * docs/realign es lo que lee quien realinea un proyecto sin contexto, persona
 * o agente, y lo sigue al pie de la letra. Una orden que la guía cita y no
 * existe —una acción renombrada, una opción que se quitó, una clave de
 * `--option` que la operación ya no lee— no falla en la guía: falla en el
 * proyecto de alguien, a mitad de un realineamiento, y el agente no tiene a
 * quién preguntar.
 *
 * Aquí se comprueba, sin interpretar la prosa, lo que la guía afirma de forma
 * literal, en el texto y dentro de los bloques de código:
 *
 *  - cada `larapack:<comando>` existe (los mismos comandos que registra
 *    `builder`), y cada `--opción` que se le pasa es suya;
 *  - en `larapack:realign`, la acción existe (RealignActions), cada opción es de esa
 *    acción (las que acepta el comando) y cada clave de `--option` la lee la
 *    operación; en `larapack:hand-rolled`, la acción es list, approve o revoke;
 *  - cada enlace relativo apunta a un archivo que existe y, con `#ancla`, a un
 *    encabezado de ese archivo;
 *  - cada acción tiene su guía, que existe, y la guía de entrada enlaza todas
 *    las páginas de docs/realign.
 *
 * Los marcadores (`<id>`, `<opción>`, `…`, `clave=valor`) no son citas: se
 * saltan.
 */
final class RealignDocTest extends TestCase
{
    /**
     * Claves de `--option` que la guía usa como marcador, no como clave.
     */
    private const PLACEHOLDER_KEYS = ['clave'];

    /**
     * @var array<string, Command>|null
     */
    private static ?array $commands = null;

    /**
     * @return array<string, array{0: string}>
     */
    public static function documents(): array
    {
        $cases = [];

        foreach (self::pages() as $page) {
            $cases[$page] = [$page];
        }

        return $cases;
    }

    #[DataProvider('documents')]
    public function test_cada_orden_que_cita_existe_con_esas_opciones(string $page): void
    {
        $problems = [];

        foreach ($this->invocations($page) as $invocation) {
            foreach ($this->problemsOf($invocation) as $problem) {
                $problems[] = "{$problem}  ←  {$invocation}";
            }
        }

        $this->assertSame([], array_values(array_unique($problems)), "docs/realign/{$page} cita órdenes que no existen así.");
    }

    #[DataProvider('documents')]
    public function test_los_enlaces_relativos_apuntan_a_algo_que_existe(string $page): void
    {
        $missing = [];
        $directory = $this->root().'/docs/realign';

        preg_match_all('/\]\(([^)\s]+)\)/', $this->prose($page), $matches);

        foreach ($matches[1] as $target) {
            if (preg_match('#^[a-z]+:#i', $target)) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $file = $path === '' ? $directory.'/'.$page : $directory.'/'.$path;

            if (! file_exists($file)) {
                $missing[] = "{$target}: no existe";

                continue;
            }

            if ($anchor !== null && str_ends_with($file, '.md') && ! in_array(rawurldecode($anchor), $this->anchors($file), true)) {
                $missing[] = "{$target}: no hay un encabezado con esa ancla";
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "docs/realign/{$page} enlaza a lo que no existe.");
    }

    public function test_cada_accion_tiene_su_guia_y_la_guia_la_nombra(): void
    {
        foreach (RealignActions::ALL as $action => $definition) {
            $this->assertFileExists($this->root().'/'.$definition['guide'], "La guía de `{$action}` no existe.");
            $this->assertStringContainsString($action, (string) file_get_contents($this->root().'/'.$definition['guide']), "{$definition['guide']} no nombra `{$action}`.");
            $this->assertNotSame([], $definition['usage'], "`{$action}` no dice cómo se usa.");

            foreach ($definition['usage'] as $usage) {
                $this->assertSame([], $this->problemsOf($usage), "El uso de `{$action}` en RealignActions cita lo que no existe: {$usage}");
            }
        }

        foreach (Operations::ACTIONS as $operation) {
            $this->assertArrayHasKey($operation, RealignActions::ALL, "La operación `{$operation}` no está en RealignActions.");
        }
    }

    public function test_la_guia_de_entrada_enlaza_todas_las_paginas(): void
    {
        $readme = $this->prose('README.md');

        foreach (self::pages() as $page) {
            if ($page !== 'README.md') {
                $this->assertStringContainsString("]({$page}", $readme, "docs/realign/README.md no enlaza {$page}: nadie la encontraría.");
            }
        }
    }

    /**
     * Lo que instala `larapack:skill` para un agente cita las mismas órdenes,
     * y con el mismo rigor: es lo primero que lee.
     */
    public function test_la_guia_para_agentes_cita_ordenes_que_existen(): void
    {
        $problems = [];

        foreach (['skill/realign/SKILL.md', 'skill/agents-block.md', 'skill/SKILL.md'] as $file) {
            foreach ($this->invocationsIn((string) file_get_contents($this->root().'/'.$file)) as $invocation) {
                foreach ($this->problemsOf($invocation) as $problem) {
                    $problems[] = "{$file}: {$problem}  ←  {$invocation}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_la_comprobacion_detecta_lo_que_no_existe(): void
    {
        // La red de esta prueba: si dejara de ver un error, todo lo de arriba
        // pasaría siempre.
        $this->assertNotSame([], $this->problemsOf('larapack:realign arreglar'));
        $this->assertNotSame([], $this->problemsOf('larapack:realign diagnose --dry-run'));
        $this->assertNotSame([], $this->problemsOf('larapack:realign adopt --option modo=estricto'));
        $this->assertNotSame([], $this->problemsOf('larapack:verify --estricto'));
        $this->assertNotSame([], $this->problemsOf('larapack:inventado'));
        $this->assertNotSame([], $this->problemsOf('larapack:hand-rolled aprobar'));
        $this->assertSame([], $this->problemsOf('larapack:realign except <id> --option reason="…" [--dry-run]'));
        $this->assertSame([], $this->problemsOf('larapack:realign apply --deterministic [--package=…]'));
        $this->assertSame([], $this->problemsOf('larapack:verify --strict --check=controller-shape --path=app/Http'));
        $this->assertSame(['verify', 'realign'], array_map(fn (string $i): string => (string) preg_replace('/^larapack:(\S+).*$/', '$1', $i),
            $this->invocationsIn("Corre `larapack:realign status` y después\n```bash\nphp artisan larapack:verify --strict   # al final\n```")));
    }

    /**
     * Las órdenes `larapack:…` citadas en una página: en los spans de código y
     * en las líneas de los bloques de código.
     *
     * @return list<string>
     */
    private function invocations(string $page): array
    {
        return $this->invocationsIn((string) file_get_contents($this->root().'/docs/realign/'.$page));
    }

    /**
     * @return list<string>
     */
    private function invocationsIn(string $markdown): array
    {
        $fragments = [];

        preg_match_all('/^[ \t]*(```|~~~)[^\n]*\n(.*?)^[ \t]*\1[ \t]*$/ms', $markdown, $blocks);

        foreach ($blocks[2] as $block) {
            foreach (explode("\n", $block) as $line) {
                // Un comentario de shell es prosa.
                $fragments[] = (string) preg_replace('/\s#\s.*$/', '', $line);
            }
        }

        $prose = (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', $markdown);
        preg_match_all('/`([^`\n]+)`/', $prose, $spans);
        array_push($fragments, ...$spans[1]);

        $invocations = [];

        foreach ($fragments as $fragment) {
            // Una línea puede encadenar órdenes: cada una, hasta el siguiente
            // separador de la shell.
            foreach (preg_split('/\s*(?:&&|\|\||;|\|)\s*/', $fragment) ?: [] as $part) {
                if (preg_match('/(larapack:[a-z][a-z-]*.*)$/', $part, $match)) {
                    $invocations[] = trim($match[1]);
                }
            }
        }

        return array_values(array_unique($invocations));
    }

    /**
     * Lo que no existe en una orden citada.
     *
     * @return list<string>
     */
    private function problemsOf(string $invocation): array
    {
        $tokens = $this->tokens($invocation);
        $name = (string) array_shift($tokens);
        $commands = self::commands();

        if (! isset($commands[$name])) {
            return ["el comando {$name} no existe"];
        }

        $command = $commands[$name];
        $definition = $command->getDefinition();
        $problems = [];
        $positionals = [];
        $options = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! str_starts_with($token, '--')) {
                $positionals[] = $token;

                continue;
            }

            $option = substr($token, 2);
            $value = null;

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            } elseif ($option !== '' && $definition->hasOption($option) && $definition->getOption($option)->acceptValue() && isset($tokens[$i + 1]) && ! str_starts_with($tokens[$i + 1], '--')) {
                $value = $tokens[++$i];
            }

            if ($option === '' || $option === '…') {
                continue;
            }

            // --no-x es la negación de una opción negable.
            $negated = str_starts_with($option, 'no-') && $definition->hasNegation($option);

            // Las de la aplicación (--help, --quiet…) valen en cualquier comando.
            if (in_array($option, ['help', 'quiet', 'silent', 'verbose', 'no-interaction', 'ansi', 'no-ansi'], true)) {
                continue;
            }

            if (! $definition->hasOption($option) && ! $negated) {
                $problems[] = "{$name} no tiene --{$option}";

                continue;
            }

            $options[] = [$option, $value];
        }

        if ($name === 'larapack:realign') {
            array_push($problems, ...$this->realignProblems($positionals, $options));
        }

        if ($name === 'larapack:hand-rolled' && isset($positionals[0]) && ! $this->isPlaceholder($positionals[0]) && ! in_array($positionals[0], ['list', 'approve', 'revoke'], true)) {
            $problems[] = "larapack:hand-rolled no tiene la acción {$positionals[0]}";
        }

        return $problems;
    }

    /**
     * @param  list<string>  $positionals
     * @param  list<array{0: string, 1: string|null}>  $options
     * @return list<string>
     */
    private function realignProblems(array $positionals, array $options): array
    {
        $action = $positionals[0] ?? 'help';

        if ($this->isPlaceholder($action)) {
            return [];
        }

        if (! isset(RealignActions::ALL[$action])) {
            return ["larapack:realign no tiene la acción {$action}"];
        }

        $problems = [];
        $accepted = [...RealignActions::options($action), 'root', 'format'];

        foreach ($options as [$option, $value]) {
            if (! in_array($option, $accepted, true)) {
                $problems[] = "larapack:realign {$action} no acepta --{$option}";

                continue;
            }

            if ($option === 'option' && $value !== null) {
                $key = explode('=', $value, 2)[0];

                if (! $this->isPlaceholder($key) && ! in_array($key, self::PLACEHOLDER_KEYS, true) && ! isset(RealignActions::ALL[$action]['keys'][$key])) {
                    $problems[] = "larapack:realign {$action} no lee --option {$key}";
                }
            }
        }

        if ($action === 'help' && isset($positionals[1]) && ! $this->isPlaceholder($positionals[1]) && ! isset(RealignActions::ALL[$positionals[1]])) {
            $problems[] = "larapack:realign help no conoce {$positionals[1]}";
        }

        return $problems;
    }

    private function isPlaceholder(string $token): bool
    {
        return $token === '' || str_contains($token, '<') || str_contains($token, '…') || str_contains($token, '|') || str_contains($token, '{');
    }

    /**
     * Los tokens de una orden como los ve la shell, sin los corchetes de lo
     * opcional y sin las comillas.
     *
     * @return list<string>
     */
    private function tokens(string $invocation): array
    {
        $invocation = str_replace(['[', ']'], ' ', $invocation);
        preg_match_all('/(?:[^\s"\']|"[^"]*"|\'[^\']*\')+/u', $invocation, $matches);

        return array_map(fn (string $token): string => str_replace(['"', "'"], '', $token), $matches[0]);
    }

    /**
     * Los comandos que registra `builder`: el mismo descubrimiento por
     * archivo, sin arrancar la aplicación.
     *
     * @return array<string, Command>
     */
    private static function commands(): array
    {
        if (self::$commands !== null) {
            return self::$commands;
        }

        $commands = [];

        foreach (glob(dirname(__DIR__, 2).'/src/Commands/*Command.php') ?: [] as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $command = new $class;
                $commands[(string) $command->getName()] = $command;
            }
        }

        return self::$commands = $commands;
    }

    /**
     * @return list<string>
     */
    private static function pages(): array
    {
        return array_map('basename', glob(dirname(__DIR__, 2).'/docs/realign/*.md') ?: []);
    }

    private function prose(string $page): string
    {
        $text = (string) file_get_contents($this->root().'/docs/realign/'.$page);

        return (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', $text);
    }

    /**
     * Las anclas de los encabezados de un archivo, con el slug de GitHub y su
     * sufijo cuando se repiten.
     *
     * @return list<string>
     */
    private function anchors(string $file): array
    {
        $text = (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', (string) file_get_contents($file));
        preg_match_all('/^#{1,6}[ \t]+(.+?)[ \t#]*$/m', $text, $matches);

        $anchors = [];
        $seen = [];

        foreach ($matches[1] as $heading) {
            $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
            $text = str_replace('`', '', $text);
            $text = mb_strtolower($text, 'UTF-8');
            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', $text));
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : "{$slug}-{$count}";
        }

        return $anchors;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
