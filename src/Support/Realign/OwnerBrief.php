<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use RuntimeException;

/**
 * `larapack:realign brief`: todo lo que espera al dueño del proyecto, en un
 * solo documento, para que un agente que llegó hasta donde podía llegar se lo
 * deje preparado y siga con lo demás.
 *
 * La prueba de autonomía de un proyecto real lo encontró: el agente llega a
 * decenas de decisiones (controladores a mano, closures, modelos que adoptar,
 * llaves foráneas) y a excepciones que sólo el dueño aprueba, y sin esto no
 * había forma de dárselas de una vez. Con esto:
 *
 *  - **cada decisión pendiente**: qué es, por qué importa, la evidencia
 *    agrupada por directorio (setenta controladores de `Admin/` son un grupo,
 *    no setenta bloques), las opciones con sus consecuencias y la orden
 *    exacta de cada una, y la recomendación del paquete **sólo** cuando una
 *    regla la sostiene (recommend()); si no, lo dice y dice cómo averiguarlo;
 *  - **cada excepción pedida sin aprobar**: el objetivo, el motivo, el mensaje
 *    del hallazgo y la orden exacta de aprobación;
 *  - qué deja bloqueado cada una y qué puede seguir haciendo el agente
 *    mientras (`meanwhile`);
 *  - un resumen arriba: cuántas, cuánto tiempo de revisión y qué pasa si no
 *    se decide nada.
 *
 * No decide ni aprueba nada, ni escribe código: sólo `brief.md` (para una
 * persona) y `brief.json` (para un agente, con `format_version`) en
 * `.larapack/realign/`. Se rehace cada vez que se corre; el estado de verdad
 * sigue siendo plan.json, exceptions.json y el candado.
 */
final class OwnerBrief
{
    public const MARKDOWN = 'brief.md';

    public const JSON = 'brief.json';

    /**
     * Cuántos elementos de un grupo se escriben en el markdown; el JSON los
     * trae todos.
     */
    private const SHOWN = 10;

    /**
     * Minutos de revisión por elemento de evidencia, por tipo de decisión: lo
     * que cuesta leer un closure o un controlador y juzgarlo. Una estimación
     * para planear la revisión, no una promesa.
     *
     * @var array<string, float>
     */
    private const MINUTES_PER_ITEM = [
        'route-closure' => 1.0,
        'unmanaged-http' => 1.0,
        'unmanaged-models' => 2.0,
        'hand-rolled' => 2.0,
        'missing' => 0.5,
        'untracked-edited' => 1.0,
    ];

    /**
     * Rutas con closure que suelen ser legítimas (decide-route-closure): sin
     * datos de nadie, con un formato fijo.
     */
    private const LEGITIMATE_CLOSURE = '#(^|/)(health|healthz|test-health|up|ping|status|csp-report|\.well-known)(/|$)#i';

    public function __construct(private readonly string $root) {}

    /**
     * El documento, sin escribir.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $steps  los del ámbito pedido
     * @param  array<string, array<string, mixed>>  $byId  todos los del plan
     * @param  list<string>  $scopes  las rutas de ámbito de --package (vacío: todos)
     * @param  list<string>  $stale
     * @return array<string, mixed>
     */
    public function build(array $plan, array $steps, array $byId, array $scopes, array $stale, ?string $diagnosedAt): array
    {
        $decisions = [];
        $meanwhile = [];

        foreach ($steps as $step) {
            $status = Planner::effectiveStatus($step, $byId);

            // Lo decidido elemento por elemento (decide --target) ya no es del
            // dueño; sólo lo que falta (Navigation::undecided()).
            if ($step['category'] === Category::DECISION && ! Navigation::decided($step) && $status !== Planner::DONE) {
                $decisions[] = $this->decision($step, $status, $byId);
            } elseif ($status === Planner::PENDING || $status === Planner::DECIDED) {
                $meanwhile[] = ['id' => $step['id'], 'title' => $step['title'], 'category' => $step['category'], 'status' => $status];
            }
        }

        $exceptions = $this->exceptions($byId, $scopes);
        // Hecho sin probar: lo prueba quien tenga el entorno, o lo acepta el
        // dueño (accept-untested). Aceptarlo es suyo, como aprobar.
        $untested = array_values(array_map(fn (array $step): array => [
            'id' => (string) $step['id'], 'title' => (string) $step['title'], 'scope' => (string) ($step['scope']['path'] ?? '.'),
            'files' => Navigation::files($step), 'why' => (string) ($step['risk']['why'] ?? ''),
            'check' => 'larapack:realign check '.self::argument((string) $step['id']).' --tested="<la orden de pruebas que pasó>"',
            'accept' => 'larapack:realign accept-untested '.self::argument((string) $step['id']).' --note="por qué y cuándo se probará"',
        ], array_filter($steps, fn (array $step): bool => ($step['status'] ?? null) === Planner::UNTESTED)));
        // Un paso decidido cuyas excepciones esperan aprobación espera al
        // dueño, no al agente.
        $waiting = array_filter(array_column($exceptions, 'step'), 'is_string');
        $meanwhile = array_values(array_filter($meanwhile, fn (array $step): bool => $step['status'] !== Planner::DECIDED || ! in_array($step['id'], $waiting, true)));
        $minutes = (int) array_sum(array_column($decisions, 'review_minutes')) + count($exceptions);

        return [
            'format_version' => RealignState::FORMAT_VERSION,
            'ok' => true,
            'action' => 'brief',
            'generated_at' => gmdate('c'),
            'diagnosed_at' => $diagnosedAt,
            'planned_at' => $plan['planned_at'] ?? null,
            'stale' => $stale,
            'summary' => [
                'decisions' => count($decisions),
                'exceptions' => count($exceptions),
                'untested' => count($untested),
                'review_minutes' => $minutes,
                'meanwhile' => count($meanwhile),
                'if_nothing_is_decided' => $decisions === [] && $exceptions === [] && $untested === []
                    ? 'No hay nada que espere al dueño.'
                    : 'Nada se rompe ni cambia en producción: estos pasos se quedan pendientes, sus hallazgos siguen como avisos y larapack:verify --strict sigue fallando, así que el realineamiento no termina. El agente sigue con lo que no depende del dueño ('.count($meanwhile).' pasos) y se detiene aquí.',
            ],
            'decisions' => $decisions,
            'exceptions' => $exceptions,
            'untested' => $untested,
            'meanwhile' => $meanwhile,
            'resume' => [
                'command' => 'larapack:realign status',
                'then' => 'larapack:realign next',
                'how' => 'Cuando el dueño haya decidido y aprobado, el agente corre status (y next) y sigue; otra vez brief dice si queda algo para el dueño.',
            ],
            'files' => ['markdown' => RealignState::DIRECTORY.'/'.self::MARKDOWN, 'json' => RealignState::DIRECTORY.'/'.self::JSON],
        ];
    }

    /**
     * Escribe brief.md y brief.json.
     *
     * @param  array<string, mixed>  $brief
     */
    public function save(array $brief): void
    {
        $state = new RealignState($this->root);
        $state->prepare();

        $json = json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        foreach ([self::JSON => $json, self::MARKDOWN => self::markdown($brief)] as $name => $content) {
            Files::guard($state->directory().'/'.$name, $this->root);

            if (file_put_contents($state->directory().'/'.$name, $content) === false) {
                throw new RuntimeException('No se pudo escribir '.$state->directory().'/'.$name.'.');
            }
        }
    }

    /**
     * El documento para una persona.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function markdown(array $brief): string
    {
        $summary = $brief['summary'];
        $lines = [
            '# Lo que necesita el dueño del proyecto',
            '',
            'Realineamiento con LaraPack (`larapack:realign`). Generado '.$brief['generated_at'].' · diagnóstico '.($brief['diagnosed_at'] ?? '—').' · plan '.($brief['planned_at'] ?? '—').'.',
            '',
        ];

        foreach ((array) $brief['stale'] as $path) {
            $lines[] = "> **Ojo:** el contrato o el manifiesto de `{$path}` cambiaron después del diagnóstico. Pide al agente `larapack:realign diagnose`, `plan` y `brief` otra vez antes de decidir.";
            $lines[] = '';
        }

        $lines[] = '## Resumen';
        $lines[] = '';
        $lines[] = "- **{$summary['decisions']} ".($summary['decisions'] === 1 ? 'decisión' : 'decisiones')."** y **{$summary['exceptions']} ".($summary['exceptions'] === 1 ? 'excepción' : 'excepciones').'** esperan al dueño. Revisión estimada: **≈ '.$summary['review_minutes'].' min**.';

        if (($summary['untested'] ?? 0) > 0) {
            $lines[] = "- **{$summary['untested']} ".($summary['untested'] === 1 ? 'paso' : 'pasos').' sin probar**: hechos, pero nadie vio pasar sus pruebas (abajo, en «Sin probar»).';
        }
        $lines[] = '- Si no se decide: '.$summary['if_nothing_is_decided'];
        $lines[] = "- Mientras tanto, el agente puede seguir con **{$summary['meanwhile']}** pasos que no dependen del dueño.";
        $lines[] = '- Cuando termines, avisa al agente: retoma con `'.$brief['resume']['command'].'`.';
        $lines[] = '';
        $lines[] = 'Corre cada orden en la raíz del proyecto (`vendor/bin/builder …` o `php artisan …`). Decidir es tuyo y aprobar también: un agente no puede correr `larapack:hand-rolled approve` (la guardia lo impide) y no decide por ti. Lo que el paquete recomienda es una regla, no tu criterio: léela antes de seguirla.';
        $lines[] = '';

        if ($brief['decisions'] !== []) {
            $lines[] = '## Decisiones ('.count($brief['decisions']).')';
            $lines[] = '';

            foreach ($brief['decisions'] as $number => $decision) {
                array_push($lines, ...self::decisionLines($number + 1, $decision));
            }
        }

        if ($brief['exceptions'] !== []) {
            $lines[] = '## Excepciones por aprobar ('.count($brief['exceptions']).')';
            $lines[] = '';
            $lines[] = 'Las pidió quien trabaja en el proyecto; no cubren nada hasta que las apruebas. Lee el motivo y el hallazgo de cada una y apruébala por su objetivo (o `larapack:hand-rolled approve --all` después de leerlas todas). Después: `git diff .larapack/hand-rolled.lock` y comitéalo: la aprobación es ese commit.';
            $lines[] = '';

            foreach ($brief['exceptions'] as $exception) {
                $where = $exception['file'].($exception['line'] === null ? '' : ':'.$exception['line']);
                $lines[] = '### `'.$exception['target'].'`';
                $lines[] = '';
                $lines[] = '- Paso: `'.($exception['step'] ?? '—').'` · en `'.$where.'` · estado: '.$exception['status'];
                $lines[] = '- Motivo: «'.$exception['reason'].'»';

                if ($exception['finding'] !== null) {
                    $lines[] = '- Hallazgo: '.$exception['finding'];
                }

                $lines[] = '- Bloquea: '.implode(', ', array_map(fn (string $item): string => '`'.$item.'`', $exception['blocks']));
                $lines[] = '';
                $lines[] = '```bash';
                $lines[] = $exception['command'];
                $lines[] = '```';
                $lines[] = '';
            }
        }

        if ($brief['untested'] !== []) {
            $lines[] = '## Sin probar ('.count($brief['untested']).')';
            $lines[] = '';
            $lines[] = 'Hechos a falta de su prueba: cambian comportamiento (un 422 que pasa a 403, un 404 que pasa a 403) y nadie registró que las pruebas del proyecto pasaran. El realineamiento no termina mientras sigan así. Quien tenga el entorno corre las pruebas y, si pasan, la primera orden; si no se pueden probar y lo aceptas así, la segunda, en tu terminal: queda en el plan con tu nota.';
            $lines[] = '';

            foreach ($brief['untested'] as $step) {
                $lines[] = '### `'.$step['id'].'`';
                $lines[] = '';
                $lines[] = '- '.$step['title'].' · `'.$step['scope'].'` · '.$step['why'];
                $lines[] = '- Archivos: '.implode(', ', array_map(fn (string $file): string => '`'.$file.'`', array_slice($step['files'], 0, self::SHOWN))).(count($step['files']) > self::SHOWN ? ' …' : '');
                $lines[] = '';
                $lines[] = '```bash';
                $lines[] = $step['check'];
                $lines[] = $step['accept'];
                $lines[] = '```';
                $lines[] = '';
            }
        }

        $lines[] = '## Lo que el agente puede seguir haciendo';
        $lines[] = '';

        if ($brief['meanwhile'] === []) {
            $lines[] = 'Nada: todo lo que queda espera al dueño.';
        }

        foreach (array_slice($brief['meanwhile'], 0, 30) as $step) {
            $lines[] = '- `'.$step['id'].'` — '.$step['title'].' ('.(Category::LABELS[$step['category']] ?? $step['category']).', '.$step['status'].')';
        }

        if (count($brief['meanwhile']) > 30) {
            $lines[] = '- … y '.(count($brief['meanwhile']) - 30).' más (brief.json los trae todos).';
        }

        $lines[] = '';
        $lines[] = '## Para retomar';
        $lines[] = '';
        $lines[] = $brief['resume']['how'];
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = $brief['resume']['command'];
        $lines[] = $brief['resume']['then'];
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return list<string>
     */
    private static function decisionLines(int $number, array $decision): array
    {
        $lines = [
            "### {$number}. {$decision['title']} — `{$decision['id']}`",
            '',
            '**Qué es.** '.$decision['description'].' Ámbito: `'.$decision['scope']['path'].'`'.($decision['scope']['package'] === null ? '' : ' ('.$decision['scope']['package'].')').'.',
            '',
            '**Por qué importa.** '.$decision['why'].' Riesgo '.$decision['risk'].', '.($decision['reversible'] ? 'reversible' : 'no reversible').'. Revisión ≈ '.$decision['review_minutes'].' min.',
            '',
        ];

        $evidence = $decision['evidence'];

        if ($evidence['count'] > 0) {
            $lines[] = '**Evidencia** ('.$evidence['count'].' en '.count($evidence['groups']).' '.(count($evidence['groups']) === 1 ? 'grupo' : 'grupos').'):';
            $lines[] = '';

            foreach ($evidence['groups'] as $group) {
                $lines[] = '- **'.$group['directory'].' ('.$group['count'].')** · '.$group['kind'].($group['message'] === null ? '' : ' — '.self::short($group['message']));

                foreach (array_slice($group['items'], 0, self::SHOWN) as $item) {
                    $lines[] = '  - `'.($item['file'] ?? '—').($item['line'] === null ? '' : ':'.$item['line']).'`'
                        .($item['subject'] === null ? '' : ' `'.$item['subject'].'`')
                        .($group['message'] === null && $item['message'] !== null ? ' — '.self::short($item['message']) : '');
                }

                if ($group['count'] > self::SHOWN) {
                    $lines[] = '  - … y '.($group['count'] - self::SHOWN).' más (brief.json los trae todos).';
                }
            }

            $lines[] = '';
        }

        $lines[] = '**Opciones:**';
        $lines[] = '';

        foreach ($decision['options'] as $option) {
            $after = array_filter([
                $option['then'] === null ? null : 'después, `'.$option['then'].'`',
                $option['playbook'] === null ? null : 'con el playbook `'.$option['playbook'].'`',
            ]);
            $lines[] = '- **'.$option['key'].'** — '.$option['label'].'. '.$option['consequences']
                .($after === [] ? '' : ' Lo hace el agente: '.implode(', ', $after).'.')
                .($option['needs_owner_again'] ? ' **Vuelve a necesitarte:** las excepciones que pida las apruebas tú.' : '');
            $lines[] = '';
            $lines[] = '  ```bash';
            $lines[] = '  '.$option['decide'];
            $lines[] = '  ```';
            $lines[] = '';
        }

        $recommendation = $decision['recommendation'];
        $lines[] = $recommendation['option'] === null
            ? '**Recomendación del paquete:** ninguna. '.$recommendation['why']
            : '**Recomendación del paquete:** `'.$recommendation['option'].'` ('.($recommendation['confidence'] === 'firm' ? 'regla firme' : 'regla tentativa: revísala').'). '.$recommendation['why'];
        $lines[] = '';
        $lines[] = '**Bloquea:** '.implode(', ', array_map(fn (string $item): string => '`'.$item.'`', $decision['blocks'])).'.';

        if ($decision['waits_for'] !== []) {
            $lines[] = 'Se puede decidir ya; lo que siga espera además a '.implode(', ', array_map(fn (string $id): string => '`'.$id.'`', $decision['waits_for'])).' (lo hace el agente).';
        }

        if ($decision['playbook'] !== null) {
            $lines[] = '';
            $lines[] = 'Cómo pensarla: `larapack:realign playbook '.$decision['playbook'].'`.';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>
     */
    private function decision(array $step, string $status, array $byId): array
    {
        $id = (string) $step['id'];
        $evidence = $this->evidence($step);
        $options = [];
        // Decidida en parte: cada orden va acotada a lo que falta. Una orden
        // sin --target sustituiría también lo ya decidido por elemento.
        $undecided = Navigation::undecided($step);
        $partial = $undecided !== ['*'] && (is_array($step['decisions'] ?? null) && $step['decisions'] !== []);
        $targets = $partial ? implode('', array_map(fn (string $file): string => ' --target='.self::argument($file), $undecided)) : '';

        foreach ((array) ($step['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $run = is_string($option['run'] ?? null) ? $option['run'] : null;
            $options[] = [
                'key' => (string) $option['key'],
                'label' => (string) $option['label'],
                'consequences' => (string) $option['consequences'],
                'decide' => 'larapack:realign decide '.self::argument($id).' '.$option['key'].$targets.' --note="…"',
                'then' => $run,
                'playbook' => is_string($option['playbook'] ?? null) ? $option['playbook'] : null,
                'needs_owner_again' => $run !== null && (str_starts_with($run, 'larapack:realign except') || str_starts_with($run, 'larapack:hand-rolled approve')),
            ];
        }

        $dependents = [];

        foreach ($byId as $other) {
            if (in_array($id, (array) ($other['depends_on'] ?? []), true) && ! Planner::finished($other)) {
                $dependents[] = (string) $other['id'];
            }
        }

        $waits = array_values(array_filter((array) ($step['depends_on'] ?? []),
            fn (mixed $dependency): bool => is_string($dependency) && isset($byId[$dependency]) && ! Planner::finished($byId[$dependency])));
        $type = (string) $step['type'];

        return [
            'id' => $id,
            'type' => $type,
            'title' => (string) $step['title'],
            'description' => (string) $step['description'],
            'why' => (string) ($step['risk']['why'] ?? ''),
            'risk' => (string) ($step['risk']['level'] ?? ''),
            'reversible' => (bool) ($step['reversible'] ?? true),
            'scope' => ['package' => $step['scope']['package'] ?? null, 'path' => (string) ($step['scope']['path'] ?? '.')],
            'status' => $status,
            'evidence' => $evidence,
            'options' => $options,
            // Decidida en parte: sólo lo que falta (los archivos de cada orden).
            'undecided' => $partial ? $undecided : null,
            'recommendation' => $this->recommend($type, $id, $evidence),
            'playbook' => is_string($step['playbook'] ?? null) ? $step['playbook'] : null,
            'blocks' => ['larapack:realign check '.self::argument($id), ...$dependents, 'larapack:verify --strict'],
            'waits_for' => $waits,
            'review_minutes' => (int) ceil(3 + (self::MINUTES_PER_ITEM[$type] ?? 1.0) * $evidence['count']),
        ];
    }

    /**
     * Lo que hay detrás de una decisión, agrupado por tipo y directorio. Un
     * closure se nombra por su llamada y su URI (`post('/csp-report')`), como
     * su excepción.
     *
     * @param  array<string, mixed>  $step
     * @return array{count: int, groups: list<array{kind: string, directory: string, count: int, message: string|null, items: list<array{file: string|null, line: int|null, subject: string|null, message: string|null}>}>}
     */
    private function evidence(array $step): array
    {
        $details = is_array($step['details'] ?? null) ? $step['details'] : [];
        $items = [];
        $closures = [];

        foreach ((array) ($details['findings'] ?? []) as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $file = is_string($finding['file'] ?? null) ? $finding['file'] : null;
            $message = (string) ($finding['message'] ?? '');
            $line = preg_match('/línea (\d+)/u', $message, $match) === 1 ? (int) $match[1] : null;
            $subject = is_string($finding['model'] ?? null) ? $finding['model'] : null;

            if (($finding['check'] ?? null) === 'route-closure' && $file !== null && $line !== null) {
                $closures[$file] ??= $this->closuresOf($file);
                $subject = $closures[$file][$line] ?? $subject;
            }

            $items[] = ['kind' => (string) ($finding['check'] ?? 'verify'), 'file' => $file, 'line' => $line, 'subject' => $subject, 'message' => $message === '' ? null : $message];
        }

        foreach ((array) ($details['unmanaged'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = ['kind' => (string) ($item['kind'] ?? 'unmanaged'), 'file' => is_string($item['file'] ?? null) ? $item['file'] : null, 'line' => null, 'subject' => null, 'message' => null];
            }
        }

        foreach ((array) ($details['files'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = ['kind' => (string) ($item['class'] ?? 'file'), 'file' => is_string($item['file'] ?? null) ? $item['file'] : null, 'line' => null,
                    'subject' => is_string($item['model'] ?? null) ? $item['model'] : null, 'message' => is_string($item['reason'] ?? null) ? $item['reason'] : null];
            }
        }

        foreach ((array) ($details['contract'] ?? []) as $item) {
            if (is_array($item)) {
                $subject = trim(($item['model'] ?? '').'.'.($item['column'] ?? ''), '.');
                $items[] = ['kind' => 'contract', 'file' => is_string($item['file'] ?? null) ? $item['file'] : null, 'line' => null, 'subject' => $subject === '' ? null : $subject,
                    'message' => isset($item['on_delete']) ? 'on_delete '.$item['on_delete'].(isset($item['on_update']) ? ', on_update '.$item['on_update'] : '') : null];
            }
        }

        // Un ámbito sin contrato o con uno inválido no trae elementos: el
        // ámbito entero es la evidencia.
        if ($items === [] && in_array($step['type'] ?? null, ['no-contract', 'contract-invalid'], true)) {
            $items[] = ['kind' => (string) $step['type'], 'file' => ExceptionTargets::contractOf((string) ($step['scope']['path'] ?? '.')), 'line' => null, 'subject' => null, 'message' => null];
        }

        $groups = [];

        foreach ($items as $item) {
            $directory = $item['file'] === null ? '(sin archivo)' : (dirname($item['file']) === '.' ? '.' : dirname($item['file']));
            $key = $item['kind'].'|'.$directory;
            $groups[$key] ??= ['kind' => $item['kind'], 'directory' => $directory, 'count' => 0, 'message' => null, 'items' => []];
            $groups[$key]['count']++;
            $groups[$key]['items'][] = ['file' => $item['file'], 'line' => $item['line'], 'subject' => $item['subject'], 'message' => $item['message']];
        }

        foreach ($groups as $key => $group) {
            usort($groups[$key]['items'], fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
            $messages = array_unique(array_map(fn (array $item): string => self::template((string) $item['message']), $group['items']));

            // El mismo mensaje en todo el grupo (salvo la línea y la llamada,
            // que ya dice cada elemento) se dice una vez.
            if (count($messages) === 1 && $group['count'] > 1 && $messages[array_key_first($messages)] !== '') {
                $groups[$key]['message'] = $messages[array_key_first($messages)];
            }
        }

        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b): int => [$b['count'], $a['directory']] <=> [$a['count'], $b['directory']]);

        return ['count' => count($items), 'groups' => $groups];
    }

    /**
     * Línea => nombre (`post('/csp-report')`) de los closures de un archivo.
     *
     * @return array<int, string>
     */
    private function closuresOf(string $file): array
    {
        $path = $this->root.'/'.$file;

        if (! is_file($path)) {
            return [];
        }

        $labels = [];

        foreach (ExceptionTargets::routeClosures($file, Files::read($path)) as $closure) {
            $labels[$closure['line']] = $closure['label'];
        }

        return $labels;
    }

    /**
     * La recomendación del paquete, sólo donde una regla de su playbook la
     * sostiene. Sin regla, `option` es null y `why` dice qué falta saber.
     *
     * @param  array{count: int, groups: list<array<string, mixed>>}  $evidence
     * @return array{option: string|null, confidence: string|null, why: string}
     */
    private function recommend(string $type, string $id, array $evidence): array
    {
        $items = [];

        foreach ($evidence['groups'] as $group) {
            array_push($items, ...array_values((array) $group['items']));
        }

        $firm = fn (string $option, string $why): array => ['option' => $option, 'confidence' => 'firm', 'why' => $why];
        $tentative = fn (string $option, string $why): array => ['option' => $option, 'confidence' => 'tentative', 'why' => $why];
        $none = fn (string $why): array => ['option' => null, 'confidence' => null, 'why' => $why];
        $basenames = array_map(fn (array $item): string => basename((string) ($item['file'] ?? $item['subject'] ?? '')), $items);

        return match ($type) {
            'route-closure' => $this->recommendClosures($items),
            'no-contract' => $firm('infer', 'Inferir sólo escribe un borrador (laraimport.inferred.json) y su informe: no toca el código ni el contrato, y adoptarlo o no se decide después, con el borrador delante.'),
            'untracked-edited' => $firm('adopt', 'Adoptarlos conserva lo escrito y lo protege de --force; la excepción también lo conserva, pero caduca con cada cambio del archivo y vuelve a pedirte aprobación.'),
            'unmanaged-models' => $items !== [] && array_diff($basenames, ['User.php']) === []
                ? $firm('keep', 'Es el User de Laravel: ya tiene su forma y no es un modelo del dominio que adoptar.')
                : $tentative('keep', 'Adoptar genera CRUD, política y rutas para cada modelo; ante la duda, no se adopta (decide-adopt-model), y adoptarlo más tarde sigue siendo posible. Si alguno se debe gestionar por la API, decide adopt y que el agente prepare el borrador con larapack:realign infer '.self::argument($id).' --dry-run.'),
            'missing' => $tentative('restore', 'El contrato los sigue pidiendo y restaurarlos se deshace con git. Si se borraron adrede, lo limpio es retirarlos del contrato (p. ej. routes.only), no una excepción.'),
            'contract-invalid' => $tentative('fix', 'Corregir el laraimport no toca el código; retirarlo deja el ámbito sin gestionar por LaraPack.'),
            'inconsistent-entity' => $items !== [] && array_diff($basenames, ['User']) === []
                ? $firm('except', 'Es el User de Laravel: le falta lo que no necesita.')
                : $none('Depende de si a ese modelo le falta algo a propósito; el mensaje de cada hallazgo dice qué.'),
            'unmanaged-http' => $none('Depende de si sus rutas se pueden conservar al declararlas: el agente lo averigua sin tocar nada con larapack:realign infer '.self::argument($id).' --dry-run, que da el informe en su resultado (una decisión route-not-preservable es que no). Dejarlos como código propio es legítimo si delegan en su request.'),
            'hand-rolled' => $none('Cada atributo trae su razón y sólo quien conoce el dominio juzga si la acción de verdad no puede delegar (firma sobre el cuerpo en bruto, formato de un protocolo, streaming).'),
            default => $none('No hay una regla del paquete para esta decisión: las opciones dicen sus consecuencias.'),
        };
    }

    /**
     * Los closures conocidos como legítimos (decide-route-closure) se
     * exceptúan; uno con otra URI probablemente lee o escribe datos y le
     * falta su request.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{option: string|null, confidence: string|null, why: string}
     */
    private function recommendClosures(array $items): array
    {
        $legitimate = [];
        $others = [];

        foreach ($items as $item) {
            $label = (string) ($item['subject'] ?? '');
            $call = strtolower((string) strtok($label, '('));
            $uri = preg_match("/'([^']*)'/", $label, $match) === 1 ? $match[1] : '';
            $known = in_array($call, ['options', 'fallback'], true) || preg_match(self::LEGITIMATE_CLOSURE, $uri) === 1;

            if ($known) {
                $legitimate[] = $label === '' ? '(sin nombre)' : $label;
            } else {
                $others[] = $label === '' ? '(línea '.($item['line'] ?? '?').')' : $label;
            }
        }

        if ($items === []) {
            return ['option' => null, 'confidence' => null, 'why' => 'No hay closures que juzgar.'];
        }

        if ($others === []) {
            return ['option' => 'except', 'confidence' => 'tentative', 'why' => 'Todas son de las que suelen ser legítimas (CORS, salud, CSP, el fallback): '.implode(', ', $legitimate).'. Comprueba que su cuerpo no lea ni escriba datos de nadie antes de decidir.'];
        }

        return ['option' => 'refactor', 'confidence' => 'tentative',
            'why' => 'No parecen de las legítimas conocidas y probablemente leen o escriben datos sin request: '.implode(', ', $others).'.'
                .($legitimate === [] ? '' : ' Las demás ('.implode(', ', $legitimate).') sí lo parecen: se pueden mezclar: refactor para las primeras y la excepción sólo para éstas.')];
    }

    /**
     * Las excepciones pedidas que no están aprobadas sobre el código de hoy.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<string>  $scopes
     * @return list<array<string, mixed>>
     */
    private function exceptions(array $byId, array $scopes): array
    {
        $rows = [];

        // La misma lista que hace fallar a `done` (Navigation::pendingExceptions()).
        foreach (Navigation::pendingExceptions(Exceptions::forRoot($this->root), $scopes, $byId) as $pending) {
            $target = $pending['target'];
            $request = $pending['request'];
            $stale = $pending['status'] === Exceptions::STALE;
            $approve = 'larapack:hand-rolled approve '.self::quote($target);
            $step = $request === null || $request['step'] === null ? null : ($byId[$request['step']] ?? null);

            $rows[] = [
                'target' => $target,
                'type' => $request['type'] ?? null,
                'step' => $request['step'] ?? null,
                'file' => $request['file'] ?? null,
                'line' => $request['line'] ?? null,
                'subject' => $request['subject'] ?? null,
                'reason' => $pending['reason'],
                'status' => $pending['status'],
                'finding' => $step === null ? null : $this->findingOf($step, $request),
                'approve' => $stale ? null : $approve,
                'command' => $stale ? 'larapack:hand-rolled revoke '.self::quote($target) : $approve,
                'blocks' => [...(($request['step'] ?? null) === null ? [] : ['larapack:realign check '.self::argument($request['step'])]), 'larapack:verify --strict'],
            ];
        }

        return $rows;
    }

    /**
     * El mensaje del hallazgo que cubre una excepción pedida.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $request
     */
    private function findingOf(array $step, array $request): string
    {
        foreach ((array) ($step['details']['findings'] ?? []) as $finding) {
            if (! is_array($finding) || ($finding['file'] ?? null) !== $request['file']) {
                continue;
            }

            $message = (string) ($finding['message'] ?? '');

            if ($request['line'] === null || str_contains($message, 'línea '.$request['line'].' ')) {
                return $message;
            }
        }

        foreach ((array) ($step['details']['unmanaged'] ?? []) as $item) {
            if (is_array($item) && ($item['file'] ?? null) === $request['file']) {
                return 'Escrito a mano ('.($item['kind'] ?? 'código').') fuera del contrato: '.$step['title'].'.';
            }
        }

        return (string) $step['title'].'.';
    }

    /**
     * Un mensaje de verify sin lo que cambia de un elemento a otro del mismo
     * grupo: la línea y la llamada de la ruta.
     */
    private static function template(string $message): string
    {
        return (string) preg_replace(['/línea \d+/u', '/\((\w+)\(\)\)/'], ['línea N', '(…)'], $message);
    }

    private static function short(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($text) > 220 ? mb_substr($text, 0, 217).'…' : $text;
    }

    /**
     * Un id de paso como argumento de una orden: entre comillas sólo si lo
     * necesita.
     */
    private static function argument(string $value): string
    {
        return preg_match('#^[\w@.:/-]+$#', $value) === 1 ? $value : self::quote($value);
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value).'"';
    }
}
