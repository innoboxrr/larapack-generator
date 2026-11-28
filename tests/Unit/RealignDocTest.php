<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\ExceptOperation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
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
 *
 * Y lo que la guía dice en tablas se cruza con las constantes del código, para
 * que no haya dos listas que mantener a mano: las acciones (RealignActions),
 * los tipos de paso y su categoría (StepTypes), los estados (Planner), las
 * clases de archivo (FileClass), los `kind` de comprobación (DoneWhen), qué
 * paso pide cada comprobación de verify, qué se puede exceptuar
 * (ExceptOperation), los estados de una excepción (Exceptions), lo que le
 * falta a `done` (Completion) y lo que cambia comportamiento. Los formatos
 * JSON, clave por clave contra la salida real, están en
 * Feature\RealignDocShapesTest.
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

    /**
     * Los playbooks son lo que `next` le da a quien hace un paso: una orden
     * que citan y no existe se copia tal cual a mitad del trabajo.
     */
    public function test_los_playbooks_citan_ordenes_que_existen(): void
    {
        $problems = [];
        $files = glob($this->root().'/resources/realign/playbooks/*.md') ?: [];

        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            foreach ($this->invocationsIn((string) file_get_contents($file)) as $invocation) {
                foreach ($this->problemsOf($invocation) as $problem) {
                    $problems[] = basename($file).": {$problem}  ←  {$invocation}";
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

    public function test_la_tabla_de_acciones_es_la_del_comando(): void
    {
        $rows = $this->table('README.md', 'Las acciones');

        $this->assertSame(array_keys(RealignActions::ALL), array_map(fn (array $row): string => $this->codes($row[0])[0] ?? '', $rows),
            'docs/realign/README.md «Las acciones» no lista las mismas acciones, en el mismo orden, que RealignActions::ALL.');

        foreach ($rows as $row) {
            $action = $this->codes($row[0])[0];

            if (preg_match('/\]\(([^)#]+)/', $row[3], $link)) {
                $this->assertSame(RealignActions::ALL[$action]['guide'], 'docs/realign/'.$link[1], "La guía de `{$action}` en la tabla no es la que da `help {$action}`.");
            } else {
                $this->assertSame(RealignActions::GUIDE, RealignActions::ALL[$action]['guide'], "`{$action}` no enlaza su guía en la tabla.");
            }
        }
    }

    public function test_la_tabla_de_tipos_de_paso_es_la_del_catalogo(): void
    {
        $documented = [];

        foreach ($this->table('plan.md', 'Los tipos de paso') as $row) {
            $documented[$this->codes($row[0])[0]] = $this->codes($row[1])[0] ?? null;
        }

        $real = array_map(fn (array $type): string => $type['category'], StepTypes::TYPES);
        ksort($documented);
        ksort($real);

        $this->assertSame($real, $documented, 'docs/realign/plan.md «Los tipos de paso» no dice los mismos tipos con la misma categoría que StepTypes::TYPES.');
    }

    public function test_la_tabla_de_estados_es_la_del_plan(): void
    {
        $documented = array_map(fn (array $row): string => $this->codes($row[0])[0], $this->table('plan.md', 'Los estados'));
        $real = [Planner::PENDING, Planner::AWAITING_DECISION, Planner::DECIDED, Planner::BLOCKED, Planner::UNTESTED, Planner::DONE];

        $this->assertSame($real, $documented, 'docs/realign/plan.md «Los estados» no lista los estados de Planner.');
    }

    public function test_la_tabla_de_kinds_es_la_de_las_comprobaciones(): void
    {
        $documented = array_map(fn (array $row): string => $this->codes($row[0])[0], $this->table('plan.md', '`check`'));

        $this->assertSame(DoneWhen::KINDS, $documented, 'docs/realign/plan.md no lista en `check` los mismos `kind` que DoneWhen::KINDS.');
    }

    public function test_la_tabla_de_clases_de_archivo_es_la_del_codigo(): void
    {
        $documented = array_map(fn (array $row): string => $this->codes($row[0])[0], $this->table('diagnose.md', 'Las clases de archivo'));

        $this->assertSame(FileClass::ALL, $documented, 'docs/realign/diagnose.md «Las clases de archivo» no lista FileClass::ALL.');

        $categories = array_map(fn (array $row): string => $this->codes($row[0])[0], $this->table('README.md', 'Las cuatro categorías'));

        $this->assertSame(Category::ALL, $categories, 'docs/realign/README.md «Las cuatro categorías» no lista Category::ALL.');
    }

    public function test_cada_comprobacion_de_verify_pide_el_paso_que_dice_la_guia(): void
    {
        $documented = [];

        foreach ($this->table('diagnose.md', 'Los hallazgos de verify') as $row) {
            $step = $this->codes($row[1])[0] ?? null;
            $category = $this->codes($row[2])[0] ?? null;

            if ($step !== null) {
                $this->assertArrayHasKey($step, StepTypes::TYPES, "La tabla de hallazgos nombra un tipo de paso que no existe: {$step}.");
                $this->assertSame(StepTypes::TYPES[$step]['category'], $category, "La tabla de hallazgos da a `{$step}` otra categoría que StepTypes.");
            }

            foreach ($this->codes($row[0]) as $check) {
                $documented[$check][] = $step;
            }
        }

        foreach (StepTypes::CHECKS as $check => $step) {
            $this->assertArrayHasKey($check, $documented, "docs/realign/diagnose.md no dice qué paso pide `{$check}`.");
            $this->assertContains($step, $documented[$check], "docs/realign/diagnose.md no da a `{$check}` el paso de StepTypes::CHECKS.");
        }
    }

    public function test_lo_que_se_puede_exceptuar_es_lo_que_acepta_except(): void
    {
        $documented = [];

        foreach ($this->table('exceptions.md', 'Qué se puede exceptuar y qué no') as $row) {
            array_push($documented, ...$this->codes($row[0]));
        }

        $real = ExceptOperation::HANDLES;
        sort($documented);
        sort($real);

        $this->assertSame($real, $documented, 'docs/realign/exceptions.md no lista los mismos tipos que ExceptOperation::HANDLES.');

        $section = $this->section('exceptions.md', 'Qué se puede exceptuar y qué no');
        $rest = substr($section, (int) strpos($section, 'No se exceptúa'));

        foreach (array_diff(array_keys(StepTypes::TYPES), ExceptOperation::HANDLES) as $type) {
            $this->assertStringContainsString("`{$type}`", $rest, "docs/realign/exceptions.md no dice que `{$type}` no se exceptúa con except.");
        }

        $text = (string) file_get_contents($this->root().'/docs/realign/exceptions.md');

        foreach ([Exceptions::UNAPPROVED, Exceptions::APPROVED, Exceptions::REASON_CHANGED, Exceptions::CODE_CHANGED, Exceptions::STALE] as $status) {
            $this->assertStringContainsString("`{$status}`", $text, "docs/realign/exceptions.md no explica el estado `{$status}` de una excepción.");
        }
    }

    public function test_lo_que_le_falta_a_done_es_lo_que_cuenta_el_codigo(): void
    {
        $documented = array_map(fn (array $row): string => $this->codes($row[0])[0], $this->table('verify.md', 'Cuándo se termina'));

        $this->assertSame(Completion::KEYS, $documented, 'docs/realign/verify.md no lista en `missing` las claves de Completion::KEYS.');

        // La página de atascos dice la salida de cada una.
        $faq = $this->section('faq.md', '`done` no termina');

        foreach (Completion::KEYS as $key) {
            $this->assertStringContainsString("`{$key}`", $faq, "docs/realign/faq.md no dice qué hacer cuando a done le falta `{$key}`.");
        }
    }

    public function test_lo_que_cambia_comportamiento_es_lo_que_dice_la_guia(): void
    {
        $section = $this->section('guided.md', 'Sin pruebas: cuando no se pueden correr las del proyecto');

        foreach (StepTypes::BEHAVIOUR as $type => $subjects) {
            $this->assertStringContainsString("`{$type}`", $section, "docs/realign/guided.md no dice que `{$type}` cambia comportamiento.");

            foreach (is_array($subjects) ? $subjects : [] as $subject) {
                $this->assertStringContainsString("`{$subject}`", $section, "docs/realign/guided.md no nombra la familia `{$subject}` de `{$type}`.");
            }
        }

        foreach (StepTypes::BEHAVIOUR_OPTIONS as $type => $options) {
            foreach ($options as $option) {
                $this->assertMatchesRegularExpression('/`'.preg_quote($type, '/').'` →\s+`'.preg_quote($option, '/').'`/', $section,
                    "docs/realign/guided.md no dice que `{$type}` decidido `{$option}` cambia comportamiento.");
            }
        }
    }

    public function test_la_recomendacion_de_cada_decision_nombra_una_opcion_que_existe(): void
    {
        $decisions = array_keys(array_filter(StepTypes::TYPES, fn (array $type): bool => $type['category'] === Category::DECISION));
        $documented = [];

        foreach ($this->table('owner.md', 'La recomendación del paquete') as $row) {
            $type = $this->codes($row[0])[0];
            $documented[] = $type;
            $option = $this->codes($row[1])[0] ?? null;

            if ($option !== null) {
                $this->assertContains($option, array_column(StepTypes::TYPES[$type]['options'] ?? [], 'key'), "docs/realign/owner.md recomienda en `{$type}` una opción que no tiene: {$option}.");
            }
        }

        sort($decisions);
        sort($documented);

        $this->assertSame($decisions, $documented, 'docs/realign/owner.md no dice qué recomienda el paquete en cada tipo de decisión.');
    }

    /**
     * Un ejemplo ```json que no parsea lo copia alguien tal cual.
     */
    public function test_cada_ejemplo_json_de_la_guia_es_json(): void
    {
        $files = [...array_map(fn (string $page): string => 'docs/realign/'.$page, self::pages()), 'skill/realign/SKILL.md'];

        foreach ($files as $file) {
            preg_match_all('/^[ \t]*```json[ \t]*\n(.*?)^[ \t]*```[ \t]*$/ms', (string) file_get_contents($this->root().'/'.$file), $blocks);

            foreach ($blocks[1] as $block) {
                $this->assertTrue($this->isJsonExample($block), "{$file} trae un ejemplo ```json que no es JSON, ni un trozo de objeto, ni un valor por línea:\n{$block}");
            }
        }

        $this->assertFalse($this->isJsonExample('{"a": 1,}'));
        $this->assertFalse($this->isJsonExample('"indexes": [ {"columns": ["a"] ]'));
    }

    /**
     * Un ejemplo vale si es un documento JSON, un trozo de objeto
     * (`"indexes": [...]`, lo que se pega dentro de un modelo) o un valor por
     * línea (varias props seguidas). «…» dentro de una cadena es un marcador
     * legítimo; fuera de ella deja de ser JSON.
     */
    private function isJsonExample(string $block): bool
    {
        $candidates = [$block, '{'.$block.'}', '['.implode(',', array_filter(array_map('trim', explode("\n", $block)), fn (string $line): bool => $line !== '')).']'];

        foreach ($candidates as $candidate) {
            json_decode($candidate);

            if (json_last_error() === JSON_ERROR_NONE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lo que pidió el dueño de la guía de entrada: dos inicios rápidos de
     * diez líneas y el ciclo entero en un diagrama.
     */
    public function test_la_guia_de_entrada_trae_los_inicios_rapidos_y_el_ciclo_completo(): void
    {
        $human = $this->section('README.md', 'Inicio rápido para una persona', false);
        preg_match('/```bash\n(.*?)```/s', $human, $block);
        $lines = array_values(array_filter(explode("\n", trim($block[1] ?? ''))));

        $this->assertCount(10, $lines, 'El inicio rápido para una persona no son diez líneas.');

        foreach ($lines as $index => $line) {
            $this->assertStringContainsString('# '.($index + 1).'. ', $line, 'Las líneas del inicio rápido no van numeradas del 1 al 10.');
        }

        preg_match_all('/^\d+\. /m', $this->section('README.md', 'Inicio rápido para un agente'), $steps);
        $this->assertCount(10, $steps[0], 'El inicio rápido para un agente no son diez pasos.');

        $cycle = $this->section('README.md', 'El ciclo completo', false);
        preg_match('/```\n(.*?)```/s', $cycle, $diagram);

        foreach (['status', 'diagnose', 'plan', 'apply --deterministic', 'next', 'defer', 'undefer', 'escalate', 'brief', 'decide', 'except', 'larapack:hand-rolled approve',
            'check', 'untested', '--tested', 'accept-untested', 'done', 'missing', 'meanwhile'] as $word) {
            $this->assertStringContainsString($word, $diagram[1] ?? '', "El diagrama del ciclo completo no nombra `{$word}`.");
        }

        // Y lo que el comando dice de sí mismo no se queda atrás.
        $this->assertStringContainsString('larapack:realign done', RealignActions::helpText());
        $this->assertStringNotContainsString('nombrándose', RealignActions::helpText());
    }

    public function test_el_glosario_define_cada_termino_una_vez_y_la_guia_no_se_queda_sin_salida(): void
    {
        $glossary = (string) file_get_contents($this->root().'/docs/realign/glossary.md');
        preg_match_all('/^\| \*\*([^*]+)\*\*/m', $glossary, $terms);
        $terms = array_map(fn (string $term): string => mb_strtolower(trim($term, '`'), 'UTF-8'), $terms[1]);

        $this->assertSame($terms, array_values(array_unique($terms)), 'El glosario define un término dos veces.');

        foreach (['contrato', 'manifiesto', 'ámbito', 'hueco', 'paso', 'categoría', 'decisión', 'excepción', 'adoptar', 'dueño', 'playbook', 'objetivo', 'huella', 'candado', 'sin probar', 'terminado', 'aplazar', 'pasar al dueño'] as $term) {
            $this->assertContains($term, $terms, "El glosario no define «{$term}».");
        }

        // Cada categoría, clase y estado que el código usa aparece en él.
        foreach ([...Category::ALL, ...array_diff(FileClass::ALL, [FileClass::ABSENT]), Planner::PENDING, Planner::AWAITING_DECISION, Planner::DECIDED, Planner::BLOCKED, Planner::UNTESTED, Planner::DONE] as $name) {
            $this->assertStringContainsString("`{$name}`", $glossary, "El glosario no nombra `{$name}`.");
        }

        // Los atascos reales, cada uno con su salida y un enlace al detalle.
        $faq = (string) file_get_contents($this->root().'/docs/realign/faq.md');

        foreach (['`next` me da una decisión y no soy el dueño', 'No puedo correr las pruebas del proyecto', '`check` dice `untested`', '`done` no termina', 'Un paso no cabe en un hueco',
            'El árbol git está sucio', 'Trabajo con el paquete fuera de `vendor/`', 'El proyecto es un monorepo', 'El proyecto no tiene contrato'] as $heading) {
            $this->assertStringContainsString("\n## {$heading}\n", $faq, "docs/realign/faq.md no responde a «{$heading}».");
            $this->assertMatchesRegularExpression('/\]\([a-zA-Z]+\.md/', $this->section('faq.md', $heading), "La respuesta a «{$heading}» no enlaza al detalle.");
        }
    }

    /**
     * El README del paquete es la puerta: tiene que mandar a la guía, y sus
     * anclas tienen que resolver (es larguísimo y se renombra por partes).
     */
    public function test_el_readme_del_paquete_manda_al_realineamiento_y_a_los_agentes(): void
    {
        $file = $this->root().'/README.md';
        $text = (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', (string) file_get_contents($file));
        $anchors = $this->anchors($file);

        foreach (['realinear-un-proyecto-existente', 'trabajar-con-agentes-de-ia', 'por-dónde-empezar'] as $anchor) {
            $this->assertContains($anchor, $anchors, "El README no tiene la sección #{$anchor}.");
        }

        preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);
        $missing = [];

        foreach (array_unique($matches[1]) as $target) {
            if (preg_match('#^[a-z]+:#i', $target)) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $linked = $path === '' ? $file : $this->root().'/'.$path;

            if (! file_exists($linked)) {
                $missing[] = "{$target}: no existe";
            } elseif ($anchor !== null && str_ends_with($linked, '.md') && ! in_array(rawurldecode($anchor), $this->anchors($linked), true)) {
                $missing[] = "{$target}: no hay un encabezado con esa ancla";
            }
        }

        $this->assertSame([], $missing, 'El README enlaza a lo que no existe.');

        $realign = (string) preg_replace('/^.*?\n## Realinear un proyecto existente\n(.*?)\n## .*$/s', '$1', $text);

        foreach (['README.md', 'faq.md', 'glossary.md', 'profiles.md', 'owner.md', 'exceptions.md', 'verify.md', 'risks.md'] as $page) {
            $this->assertStringContainsString("docs/realign/{$page}", $realign, "«Realinear un proyecto existente» no enlaza docs/realign/{$page}.");
        }

        $agents = (string) preg_replace('/^.*?\n## Trabajar con agentes de IA\n(.*?)\n## .*$/s', '$1', $text);

        $this->assertStringContainsString('docs/realign/README.md#inicio-rápido-para-un-agente', $agents);
        $this->assertStringContainsString('docs/realign/owner.md', $agents);

        // La lista de acciones de la referencia de comandos no se queda atrás.
        preg_match('/^larapack:realign .*?(?=\n\n)/ms', (string) file_get_contents($file), $reference);

        foreach (array_diff(array_keys(RealignActions::ALL), ['help']) as $action) {
            $this->assertMatchesRegularExpression('/(^|[\s,])'.preg_quote($action, '/').'([\s,.]|$)/', $reference[0] ?? '', "La referencia de comandos del README no nombra la acción {$action}.");
        }
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

    /**
     * Las filas de la primera tabla bajo un encabezado de una página, sin la
     * cabecera: cada fila, sus celdas.
     *
     * @return list<list<string>>
     */
    private function table(string $page, string $heading): array
    {
        $rows = [];

        foreach (explode("\n", $this->section($page, $heading)) as $line) {
            if (! str_starts_with($line, '|')) {
                if ($rows !== []) {
                    break;
                }

                continue;
            }

            $rows[] = array_map('trim', explode('|', trim($line, '| ')));
        }

        $this->assertGreaterThan(2, count($rows), "docs/realign/{$page} no tiene una tabla bajo «{$heading}».");

        // La cabecera y la línea de guiones. Una barra dentro de una celda
        // (`low\|medium`) no se usa en las tablas que se cruzan con el código.
        return array_slice($rows, 2);
    }

    /**
     * El texto bajo un encabezado, hasta el siguiente del mismo nivel o
     * superior; sin los bloques de código, salvo que se pidan.
     */
    private function section(string $page, string $heading, bool $prose = true): string
    {
        $text = (string) file_get_contents($this->root().'/docs/realign/'.$page);

        if (! preg_match('/^(#{1,6})[ \t]+'.preg_quote($heading, '/').'[ \t]*$/m', $text, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail("docs/realign/{$page} no tiene el encabezado «{$heading}».");
        }

        $body = substr($text, $match[0][1] + strlen($match[0][0]));
        $level = strlen($match[1][0]);
        $withoutCode = (string) preg_replace_callback('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', fn (array $block): string => str_repeat("\n", substr_count($block[0], "\n")), $body);

        if (preg_match('/^#{1,'.$level.'}[ \t]+\S/m', $withoutCode, $next, PREG_OFFSET_CAPTURE)) {
            // Los bloques se sustituyeron por el mismo número de líneas: el
            // corte cae en la misma línea del texto original.
            $lines = substr_count(substr($withoutCode, 0, $next[0][1]), "\n");
            $body = implode("\n", array_slice(explode("\n", $body), 0, $lines));
            $withoutCode = implode("\n", array_slice(explode("\n", $withoutCode), 0, $lines));
        }

        return $prose ? $withoutCode : $body;
    }

    /**
     * Los nombres entre comillas invertidas de una celda.
     *
     * @return list<string>
     */
    private function codes(string $cell): array
    {
        preg_match_all('/`([^`]+)`/', $cell, $matches);

        return $matches[1];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
