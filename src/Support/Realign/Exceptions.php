<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use RuntimeException;
use Throwable;

/**
 * Las excepciones del realineamiento: lo que se decide dejar como está
 * aunque LaraPack lo señale, para lo que no puede llevar #[HandRolled] —un
 * closure de una ruta legítimo (el fallback de la SPA según el Host,
 * `/csp-report`, el `OPTIONS` del CORS), un controlador o un modelo que se
 * deja fuera del contrato, un archivo generado que se borró a propósito, un
 * hallazgo de verify que se acepta—.
 *
 * Tienen dos mitades, como las de #[HandRolled], y por la misma razón: quien
 * pide la excepción no puede ser quien la aprueba.
 *
 *  - **La solicitud** (`.larapack/realign/exceptions.json`): la escribe
 *    `larapack:realign except <paso> --option reason="…"`, la puede escribir
 *    un agente, y no cubre nada. Es el equivalente del atributo: dice qué y
 *    por qué.
 *  - **La aprobación**: una entrada en `.larapack/hand-rolled.lock` con el
 *    objetivo (ExceptionTargets), la razón y la huella de lo que cubre. La
 *    escribe sólo `larapack:hand-rolled approve`, que corre el dueño del
 *    proyecto; la guardia de los agentes impide correrlo o editar el candado,
 *    el hook de git pide confirmarlo en la terminal y CODEOWNERS, su revisión
 *    (GitHooks, CiGate). Es el mismo candado a propósito: todo lo que ya
 *    protege la aprobación de #[HandRolled] protege ésta sin una línea más.
 *
 * Una aprobación cubre sólo lo que nombra su objetivo y **sólo mientras la
 * huella no cambie**: si el código que cubre cambia, deja de cubrirlo, el
 * hallazgo vuelve a salir diciendo que la excepción era de otro código, y
 * hay que volver a aprobarla. Una que ya no cubre nada sale como
 * `hand-rolled-stale` (ProjectVerification) y el dueño la retira con
 * `larapack:hand-rolled revoke`.
 *
 * El formato de la solicitud:
 *
 *     {
 *       "format_version": 1,
 *       "requests": [
 *         {"target": "realign:route-closure:routes/web.php#get('/csp-report')",
 *          "type": "route-closure", "check": "route-closure", "scope": ".", "file": "routes/web.php",
 *          "subject": "get('/csp-report')", "line": 84, "step": "route-closure@.",
 *          "message": "…", "reason": "…", "fingerprint": "sha256:…", "requested_at": "…"}
 *       ]
 *     }
 *
 * `message` es el del hallazgo que se pidió exceptuar (desde 9.4.0; null en
 * lo que no sale de un hallazgo). Lo escribe quien pide, así que no aprueba
 * nada: al aprobar el de un hallazgo sin archivo (`model:`), el dueño ve el
 * hallazgo **de hoy** —verify se corre— y se aprueba su huella, no la de la
 * solicitud (statements()).
 *
 * @phpstan-type Request array{target: string, type: string, check: string|null, scope: string, file: string, subject: string|null, line: int|null, step: string|null, message: string|null, reason: string, fingerprint: string|null, requested_at: string}
 * @phpstan-type Approval array{reason: string, fingerprint: string|null, approved_by?: string|null}
 * @phpstan-type Item array{type?: string|null, scope?: string|null, file?: string|null, check?: string|null, model?: string|null, message?: string|null}
 */
final class Exceptions
{
    public const FILE = 'exceptions.json';

    /**
     * El estado de una excepción, como lo lista `larapack:hand-rolled list`.
     */
    public const APPROVED = 'approved';

    public const UNAPPROVED = 'unapproved';

    public const REASON_CHANGED = 'reason-changed';

    public const CODE_CHANGED = 'code-changed';

    public const STALE = 'stale';

    /**
     * Lo que verify no cubre con una excepción del realineamiento: lo que ya
     * tiene su propio mecanismo de aprobación (#[HandRolled]) y el closure de
     * una ruta, que se resuelve en ControllerShape, donde se sabe de qué
     * closure es cada hallazgo.
     */
    private const OWN_MECHANISM = ['hand-rolled', 'hand-rolled-unapproved', 'hand-rolled-stale', 'route-closure', 'controller-shape', 'request-handle', 'controller-concern'];

    private string $root;

    /**
     * Las solicitudes, leídas la primera vez que hacen falta: cubrir no las
     * necesita, y una solicitud mal escrita no puede tumbar verify.
     *
     * @var array<string, Request>|null
     */
    private ?array $requests = null;

    /**
     * Las aprobaciones del realineamiento del candado, por objetivo.
     *
     * @var array<string, Approval>
     */
    private array $approved = [];

    private ?string $lockError = null;

    /**
     * @var array<string, string|null>
     */
    private array $fingerprints = [];

    /**
     * Los objetivos que cubrieron algo en applyTo().
     *
     * @var array<string, true>
     */
    private array $used = [];

    /**
     * Los mensajes de hoy de los hallazgos de verify sin archivo (los de una
     * entidad), por su objetivo, de las comprobaciones ya vistas (observe()).
     *
     * @var array<string, string>
     */
    private array $statements = [];

    /**
     * Las comprobaciones cuyos hallazgos ya se vieron.
     *
     * @var array<string, true>
     */
    private array $observed = [];

    public function __construct(private readonly RealignState $state)
    {
        $this->root = rtrim(str_replace('\\', '/', $state->root()), '/');
        try {
            foreach (HandRolledLock::read($this->root) ?? [] as $target => $approval) {
                if (ExceptionTargets::isRealign((string) $target)) {
                    $this->approved[(string) $target] = $approval;
                }
            }
        } catch (RuntimeException $e) {
            // Un candado ilegible no aprueba nada; verify ya lo dice como
            // error (ControllerShape) y `larapack:hand-rolled` no lo toca.
            $this->lockError = $e->getMessage();
        }
    }

    public static function forRoot(string $root): self
    {
        return new self(new RealignState($root));
    }

    public function requestsPath(): string
    {
        return $this->state->directory().'/'.self::FILE;
    }

    public function lockError(): ?string
    {
        return $this->lockError;
    }

    /**
     * Las aprobaciones del realineamiento, con su objetivo y si siguen
     * cubriendo el mismo código.
     *
     * @return list<array{target: string, reason: string, fingerprint: string|null, status: string}>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->approved as $target => $approval) {
            $all[] = ['target' => $target, 'reason' => $approval['reason'], 'fingerprint' => $approval['fingerprint'], 'status' => $this->status($target)];
        }

        return $all;
    }

    /**
     * @return array<string, Request>
     */
    public function requests(): array
    {
        return $this->requests ??= self::loadRequests($this->requestsPath());
    }

    /**
     * @return array<string, Approval>
     */
    public function approvals(): array
    {
        return $this->approved;
    }

    /**
     * Si un elemento del diagnóstico (un archivo, código sin gestionar, un
     * hallazgo de verify) lo cubre una excepción aprobada sobre el mismo
     * código. Sin aprobación —sólo solicitada— no lo cubre.
     *
     * @param  Item  $item
     */
    public function covers(array $item): bool
    {
        return $this->approvedFor($item) !== null;
    }

    /**
     * La excepción aprobada que cubre un elemento, o null.
     *
     * @param  Item  $item
     * @return array{target: string, reason: string}|null
     */
    public function approvedFor(array $item): ?array
    {
        $target = $this->targetOf($item);

        if ($target === null || ! isset($this->approved[$target])) {
            return null;
        }

        $approval = $this->approved[$target];
        $current = $this->currentFingerprint($target, $item);

        if ($approval['fingerprint'] === null || $current !== $approval['fingerprint']) {
            return null;
        }

        return ['target' => $target, 'reason' => $approval['reason']];
    }

    /**
     * La excepción aprobada para un elemento cuyo código cambió desde que se
     * aprobó: ya no lo cubre, y hay que decirlo.
     *
     * @param  Item  $item
     */
    public function expiredFor(array $item): ?string
    {
        $target = $this->targetOf($item);

        return $target !== null && isset($this->approved[$target]) && $this->approvedFor($item) === null ? $target : null;
    }

    /**
     * El objetivo de un elemento del diagnóstico o de un hallazgo de verify.
     * null si no se puede nombrar con exactitud (un closure sin su línea).
     *
     * @param  Item  $item
     */
    public function targetOf(array $item): ?string
    {
        $type = $item['type'] ?? null;
        $check = $item['check'] ?? null;
        $file = $item['file'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        if ($type === 'no-contract') {
            return ExceptionTargets::forFile($type, null, ExceptionTargets::contractOf((string) ($item['scope'] ?? '.')));
        }

        if ($type === 'route-closure') {
            return null;
        }

        if (is_string($file) && $file !== '') {
            return ExceptionTargets::forFile($type, $check, $file);
        }

        $model = $item['model'] ?? null;
        $message = $item['message'] ?? null;

        return is_string($model) && is_string($message) ? ExceptionTargets::forStatement($type, $check, $model, $message) : null;
    }

    /**
     * El estado de una excepción del realineamiento:
     *
     *  - `approved`: aprobada, sobre el mismo código;
     *  - `unapproved`: solicitada y sin aprobar;
     *  - `reason-changed`: aprobada con otra razón que la de su solicitud;
     *  - `code-changed`: aprobada sobre otro código (la huella no coincide);
     *  - `stale`: aprobada sobre algo que ya no existe (un closure que no
     *    está, el hallazgo de una entidad que verify ya no dice).
     */
    public function status(string $target): string
    {
        $approval = $this->approved[$target] ?? null;
        $request = $this->requests()[$target] ?? null;

        if ($approval === null) {
            return self::UNAPPROVED;
        }

        if ($request !== null && $request['reason'] !== $approval['reason']) {
            return self::REASON_CHANGED;
        }

        // La de una entidad, como las demás: con la huella de su hallazgo de
        // hoy (statements()). Antes salía siempre aprobada.
        $current = $this->fingerprint($target);

        return match (true) {
            $current === null => self::STALE,
            $current !== $approval['fingerprint'] => self::CODE_CHANGED,
            default => self::APPROVED,
        };
    }

    /**
     * La huella actual de lo que cubre un objetivo (con caché). La de un
     * hallazgo sin archivo (`model:`) es la de su mensaje de hoy, y null si
     * verify ya no lo dice.
     */
    public function fingerprint(string $target): ?string
    {
        if (self::isStatement($target)) {
            $message = $this->statement($target);

            return $message === null ? null : ExceptionTargets::statementFingerprint($message);
        }

        if (! array_key_exists($target, $this->fingerprints)) {
            $this->fingerprints[$target] = ExceptionTargets::fingerprint($this->root, $target);
        }

        return $this->fingerprints[$target];
    }

    /**
     * Si el objetivo es el de un hallazgo sin archivo (el de una entidad).
     */
    public static function isStatement(string $target): bool
    {
        $parts = ExceptionTargets::parse($target);

        return $parts !== null && str_starts_with($parts['file'], 'model:');
    }

    /**
     * El mensaje de hoy del hallazgo sin archivo que nombra el objetivo, o
     * null si verify ya no lo dice. Si su comprobación aún no se vio, corre
     * verify con ella (una vez).
     */
    public function statement(string $target): ?string
    {
        $parts = ExceptionTargets::parse($target);

        if ($parts === null || ! self::isStatement($target)) {
            return null;
        }

        $check = $parts['check'] ?? $parts['type'];

        if (! in_array($check, Verifier::CHECKS, true)) {
            return null;
        }

        if (! isset($this->observed[$check])) {
            // Se marca antes: la verificación vuelve a este objeto (observe(),
            // all()) y no debe correrse dentro de sí misma.
            $this->observed[$check] = true;

            try {
                (new ProjectVerification($this->root, exceptions: $this))->run([$check]);
            } catch (Throwable) {
                // Sin verify (un proyecto sin contrato legible) no hay
                // hallazgo de hoy: no se aprueba ni se da por aprobada.
            }
        }

        return $this->statements[$target] ?? null;
    }

    /**
     * Lo que dijo verify, tal cual (antes de review()): de cada hallazgo sin
     * archivo, su mensaje por su objetivo. `$ran`, las comprobaciones que se
     * corrieron: de ellas, lo que no salga ya no está.
     *
     * @param  array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>  $findings
     * @param  array<int, string>  $ran
     */
    public function observe(array $findings, array $ran): void
    {
        foreach ($ran as $check) {
            $this->observed[$check] = true;
        }

        foreach ($findings as $finding) {
            if (($finding['file'] ?? null) !== null && $finding['file'] !== '') {
                continue;
            }

            $target = $this->targetOf(['type' => StepTypes::forFinding($finding), 'check' => $finding['check'],
                'model' => $finding['model'], 'message' => $finding['message']]);

            if ($target !== null) {
                $this->statements[$target] = $finding['message'];
            }
        }
    }

    /**
     * Verify con las excepciones: lo que cubre una aprobada sobre el mismo
     * código pasa a `info` diciendo que es una excepción aprobada, y lo que
     * cubría una aprobada cuyo código cambió lo dice en su mensaje, sin bajar
     * de nivel. Devuelve, por posición, el nivel y el mensaje nuevos de lo
     * que cambia; quien llama los aplica (applyTo()).
     *
     * Los closures de rutas y lo que tiene su propio mecanismo (#[HandRolled])
     * no pasan por aquí (OWN_MECHANISM).
     *
     * @param  array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>  $findings  con las rutas relativas a la raíz de este proyecto
     * @return array<int, array{level: string, message: string}>
     */
    public function review(array $findings): array
    {
        $changes = [];

        if ($this->approved === []) {
            return $changes;
        }

        foreach ($findings as $index => $finding) {
            if ($finding['level'] === Verifier::INFO || in_array($finding['check'], self::OWN_MECHANISM, true)) {
                continue;
            }

            $item = ['type' => StepTypes::forFinding($finding), 'check' => $finding['check'], 'file' => $finding['file'],
                'model' => $finding['model'], 'message' => $finding['message']];
            $approved = $this->approvedFor($item);

            if ($approved !== null) {
                $this->used[$approved['target']] = true;
                $changes[$index] = ['level' => Verifier::INFO,
                    'message' => $finding['message'].' Excepción aprobada por el dueño del proyecto en '.HandRolledLock::PATH.': «'.self::sanitise($approved['reason']).'» ('.$approved['target'].').'];

                continue;
            }

            $expired = $this->expiredFor($item);

            if ($expired !== null) {
                $this->used[$expired] = true;
                $changes[$index] = ['level' => $finding['level'],
                    'message' => $finding['message']." La excepción aprobada {$expired} era de otro código: ya no lo cubre. El dueño del proyecto la vuelve a aprobar (larapack:hand-rolled approve) o se corrige lo que dice este hallazgo."];
            }
        }

        return $changes;
    }

    /**
     * review() aplicado a una lista de hallazgos de verify.
     *
     * @param  list<array{level: string, check: string, model: string|null, file: string|null, message: string}>  $findings
     * @return list<array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    public function applyTo(array $findings): array
    {
        foreach ($this->review($findings) as $index => $change) {
            $findings[$index] = array_merge($findings[$index], $change);
        }

        return $findings;
    }

    /**
     * Lo aprobado que ya no cubre ningún hallazgo de verify: avisos
     * `hand-rolled-stale` en el candado, como los de #[HandRolled].
     *
     * Sólo se puede saber de las que cubren un hallazgo de verify (las que
     * llevan comprobación en su objetivo o la tienen por tipo) y sólo si su
     * comprobación se acaba de correr: con `--check` acotado, de las demás no
     * se dice nada.
     *
     * @param  array<int, string>  $ran  las comprobaciones que se corrieron
     * @param  callable(string): bool  $selected  si el archivo es de lo que se midió
     * @return list<array{level: string, check: string, model: null, file: string, message: string}>
     */
    public function staleFindings(array $ran, callable $selected): array
    {
        $stale = [];

        foreach ($this->approved as $target => $approval) {
            $parts = ExceptionTargets::parse($target);

            if ($parts === null || isset($this->used[$target])) {
                continue;
            }

            $check = $parts['check'] ?? $parts['type'];

            if (! in_array($check, Verifier::CHECKS, true) || in_array($check, self::OWN_MECHANISM, true) || ! in_array($check, $ran, true)) {
                continue;
            }

            if (! str_starts_with($parts['file'], 'model:') && ! $selected($parts['file'])) {
                continue;
            }

            $stale[] = ['level' => Verifier::WARNING, 'check' => 'hand-rolled-stale', 'model' => null, 'file' => HandRolledLock::PATH,
                'message' => 'Aprueba la excepción '.self::sanitise($target).', que ya no cubre ningún hallazgo de verify (se corrigió, o el código cambió). El dueño del proyecto la retira con larapack:hand-rolled revoke.'];
        }

        return $stale;
    }

    /**
     * Anota solicitudes nuevas, o les cambia la razón.
     *
     * @param  list<Request>  $requests
     */
    public function saveRequests(array $requests): void
    {
        $all = $this->requests();

        foreach ($requests as $request) {
            $all[$request['target']] = $request;
        }

        ksort($all, SORT_STRING);
        $this->requests = $all;
        $this->state->prepare();

        $json = json_encode(['format_version' => RealignState::FORMAT_VERSION, 'requests' => array_values($all)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($this->requestsPath(), $json) === false) {
            throw new RuntimeException('No se pudo escribir '.$this->requestsPath().'.');
        }
    }

    /**
     * La razón la escribe quien pide la excepción: en una línea, sin
     * caracteres de control ni de formato invisibles.
     */
    public static function sanitise(string $reason): string
    {
        $clean = preg_replace(['/[\p{Cc}\p{Cf}]+/u', '/\s+/u'], ' ', mb_scrub($reason, 'UTF-8'));

        return trim((string) $clean);
    }

    /**
     * @param  Item  $item
     */
    private function currentFingerprint(string $target, array $item): ?string
    {
        $parts = ExceptionTargets::parse($target);

        if ($parts !== null && str_starts_with($parts['file'], 'model:')) {
            return is_string($item['message'] ?? null) ? ExceptionTargets::statementFingerprint($item['message']) : null;
        }

        return $this->fingerprint($target);
    }

    /**
     * @return array<string, Request>
     */
    private static function loadRequests(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode(Files::read($path), true);

        if (! is_array($decoded) || ! is_array($decoded['requests'] ?? [])) {
            throw new RuntimeException("{$path} no tiene la forma de las solicitudes de excepción del realineamiento (un objeto con una lista `requests`). Corrígelo o bórralo y vuelve a pedirlas con `larapack:realign except`.");
        }

        RealignState::assertReadable($path, $decoded);

        $requests = [];

        foreach ((array) ($decoded['requests'] ?? []) as $request) {
            // Una solicitud sin razón no se puede revisar, y una sin objetivo
            // del realineamiento no dice qué cubriría: no cuentan.
            if (! is_array($request) || ! is_string($request['target'] ?? null) || ExceptionTargets::parse($request['target']) === null
                || ! is_string($request['reason'] ?? null) || self::sanitise($request['reason']) === '') {
                continue;
            }

            $parts = (array) ExceptionTargets::parse($request['target']);

            $requests[$request['target']] = [
                'target' => $request['target'],
                'type' => (string) $parts['type'],
                'check' => is_string($request['check'] ?? null) ? $request['check'] : null,
                'scope' => is_string($request['scope'] ?? null) ? $request['scope'] : '.',
                'file' => (string) $parts['file'],
                'subject' => is_string($parts['subject'] ?? null) ? $parts['subject'] : null,
                'line' => is_int($request['line'] ?? null) ? $request['line'] : null,
                'step' => is_string($request['step'] ?? null) ? $request['step'] : null,
                'message' => is_string($request['message'] ?? null) ? $request['message'] : null,
                'reason' => self::sanitise($request['reason']),
                'fingerprint' => is_string($request['fingerprint'] ?? null) ? $request['fingerprint'] : null,
                'requested_at' => is_string($request['requested_at'] ?? null) ? $request['requested_at'] : '',
            ];
        }

        ksort($requests, SORT_STRING);

        return $requests;
    }
}
