<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mantiene `.larapack/hand-rolled.lock`: qué excepciones #[HandRolled] aprobó
 * el dueño del proyecto.
 *
 * Aprobar es esto: el dueño corre `approve`, revisa el diff del candado y lo
 * comitea. Se aprueba la razón y la huella del código tal como está ahora
 * (HandRolledLock): si el código cambia, la excepción vuelve a pedir
 * aprobación, y `approve` de nuevo recalcula la huella. El comando no pregunta nada ni comprueba quién lo corre —en
 * Windows no hay forma fiable de saber si hay una terminal delante, y una
 * pregunta se contesta con una tubería—; lo que lo hace del dueño es que el
 * candado pide su revisión (CODEOWNERS, `larapack:ci --owner`) y que la
 * guardia de los agentes bloquea `approve` y `revoke` antes de que se
 * ejecuten.
 *
 * Los objetivos se escriben como los da `list`: el nombre completo de la
 * clase, con `::metodo` si la excepción es de una acción. Basta el final que
 * lo distinga —`TokenController::issue`—, para no tener que escapar las
 * barras invertidas en la terminal; si coincide con más de uno, no se adivina.
 *
 * Desde 9.4.0 aprueba también las excepciones del realineamiento: lo que no
 * puede llevar atributo (el closure de una ruta, un controlador o un modelo
 * que se deja fuera del contrato, un hallazgo que se acepta). Las pide
 * cualquiera con `larapack:realign except` (Realign\Exceptions); su objetivo
 * empieza por `realign:` y basta su final —`routes/web.php#fallback()`—. Se
 * aprueban en este mismo candado, con la razón de la solicitud y la huella de
 * lo que cubren hoy, y por eso las protege todo lo que ya protege la
 * aprobación de #[HandRolled].
 */
class HandRolledCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    private const ACTIONS = ['list', 'approve', 'revoke'];

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:hand-rolled')
            ->setDescription('Lista, aprueba o retira las excepciones #[HandRolled] (lo corre el dueño del proyecto)')
            ->addArgument('action', InputArgument::REQUIRED, 'list, approve o revoke')
            ->addArgument('targets', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Clase o Clase::metodo, como los da list')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Con approve: aprueba todas las excepciones del código')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $action = (string) $input->getArgument('action');
        /** @var list<string> $targets */
        $targets = array_values(array_unique(array_map(
            fn (string $target): string => ltrim(trim($target), '\\'),
            (array) $input->getArgument('targets')
        )));
        $all = (bool) $input->getOption('all');

        // Sin abreviaturas: la guardia de los agentes reconoce `approve` y
        // `revoke` por su nombre exacto.
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->reportFailure($input, $output, "Acción desconocida: {$action}.", 'Usa list, approve o revoke.');
        }

        if ($action === 'list' && ($targets !== [] || $all)) {
            return $this->reportFailure($input, $output, 'list no recibe objetivos ni --all.');
        }

        if ($action === 'revoke' && $all) {
            return $this->reportFailure($input, $output, 'revoke no admite --all: retira cada excepción por su nombre.');
        }

        if ($action !== 'list' && ($targets === []) === ! $all) {
            return $this->reportFailure($input, $output,
                $action === 'approve' ? 'Indica qué aprobar, o --all.' : 'Indica qué retirar.',
                'Los objetivos salen de larapack:hand-rolled list.');
        }

        $root = root_path();
        $shape = new ControllerShape($root);
        $shape->run();
        $exceptions = $shape->exceptions();

        try {
            $approved = HandRolledLock::read($root);
        } catch (RuntimeException $e) {
            // No se reescribe un candado que no se entiende: lo que hubiera
            // dentro se perdería sin que nadie lo revisara.
            return $this->reportFailure($input, $output, $e->getMessage(), 'Corrígelo a mano (o bórralo y aprueba de nuevo); no se toca.');
        }

        try {
            $realign = Exceptions::forRoot($root);
            $requested = $realign->requests();
        } catch (RuntimeException $e) {
            return $this->reportFailure($input, $output, $e->getMessage(), 'Las solicitudes las escribe larapack:realign except; corrígelas o bórralas y vuelve a pedirlas.');
        }

        if ($action === 'list') {
            return $this->list($input, $output, $exceptions, $approved, $realign);
        }

        // Lo que se puede aprobar: cada #[HandRolled] con razón del código y
        // cada excepción del realineamiento solicitada, con la huella de lo
        // que cubre hoy. Una cuyo código ya no existe no se aprueba.
        foreach ($requested as $target => $request) {
            $fingerprint = str_starts_with($request['file'], 'model:') ? $request['fingerprint'] : $realign->fingerprint($target);

            if ($fingerprint !== null) {
                $exceptions[$target] = ['reason' => $request['reason'], 'fingerprint' => $fingerprint, 'file' => $request['file'], 'line' => $request['line'] ?? 0];
            }
        }

        $current = $approved ?? [];
        $pool = $action === 'approve' ? array_keys($exceptions) : array_keys($current);
        $chosen = [];

        foreach ($all ? $pool : $targets as $target) {
            $matches = $all ? [$target] : $this->resolve($target, $pool);

            if (count($matches) !== 1) {
                return $this->reportFailure($input, $output, $this->unresolved($action, $target, $matches));
            }

            $chosen[] = $matches[0];
        }

        $chosen = array_values(array_unique($chosen));
        sort($chosen, SORT_STRING);
        $next = $current;

        foreach ($chosen as $target) {
            if ($action === 'approve') {
                $next[$target] = ['reason' => $exceptions[$target]['reason'], 'fingerprint' => $exceptions[$target]['fingerprint']];
            } else {
                unset($next[$target]);
            }
        }

        // Sin candado y sin nada que aprobar no se crea uno vacío: el candado
        // cambia el modo de verify —un #[HandRolled] nuevo pasa de aviso a
        // error—, y eso no puede ocurrir de rebote de un `approve --all` que
        // no aprobó nada. Sin candado, `verify --strict` ya falla con el
        // aviso de cualquier excepción nueva.
        if ($approved === null && $chosen === []) {
            return $this->nothingToApprove($input, $output);
        }

        $changed = $approved === null || HandRolledLock::encode($next) !== HandRolledLock::encode($current);

        if ($changed) {
            HandRolledLock::write($root, $next);
        }

        $done = array_map(fn (string $target): array => ['target' => $target]
            + ($action === 'approve' ? ['reason' => $exceptions[$target]['reason']] : []), $chosen);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['ok' => true, 'action' => $action, 'lock' => HandRolledLock::PATH, 'changed' => $changed, 'targets' => $done]);

            return Command::SUCCESS;
        }

        foreach ($done as $entry) {
            $output->writeln(sprintf('  <info>%s</info> %s%s',
                $action === 'approve' ? 'aprobada' : 'retirada',
                OutputFormatter::escape($entry['target']),
                isset($entry['reason']) ? ': «'.OutputFormatter::escape($entry['reason']).'»' : ''
            ));
        }

        if ($done === []) {
            $output->writeln('  No hay excepciones #[HandRolled] en el código.');
        }

        if ($changed && $approved === null) {
            $output->writeln("\n  Se creó ".HandRolledLock::PATH.': desde ahora un #[HandRolled] que no esté en él es error en verify, no aviso.');
        }

        $output->writeln($changed
            ? "\n  Revisa el diff de ".HandRolledLock::PATH.' y comitéalo: la aprobación es ese commit.'
            : "\n  ".HandRolledLock::PATH.' ya estaba así.');

        return Command::SUCCESS;
    }

    /**
     * `approve --all` sin candado y sin excepciones en el código: no escribe.
     */
    private function nothingToApprove(InputInterface $input, OutputInterface $output): int
    {
        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['ok' => true, 'action' => 'approve', 'lock' => null, 'changed' => false, 'targets' => []]);

            return Command::SUCCESS;
        }

        $output->writeln([
            '  No hay excepciones #[HandRolled] en el código: no se creó '.HandRolledLock::PATH.'.',
            '  Sin candado, verify --strict ya falla con cualquier #[HandRolled] nuevo (es aviso); el candado se',
            '  crea con la primera aprobación.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Las excepciones del código y las del candado, con su estado; después,
     * las del realineamiento (solicitadas o aprobadas).
     *
     * @param  array<string, array{reason: string, fingerprint: string, file: string, line: int}>  $exceptions
     * @param  array<string, array{reason: string, fingerprint: string|null}>|null  $approved
     */
    private function list(InputInterface $input, OutputInterface $output, array $exceptions, ?array $approved, Exceptions $realign): int
    {
        $rows = [];
        $requested = $realign->requests();
        $approved = $approved === null ? null : array_filter($approved, fn (string|int $target): bool => ! ExceptionTargets::isRealign((string) $target), ARRAY_FILTER_USE_KEY);

        foreach ($exceptions as $target => $exception) {
            $locked = $approved[$target] ?? null;

            $rows[] = [
                'target' => $target,
                'reason' => $exception['reason'],
                'file' => $exception['file'],
                'line' => $exception['line'],
                'status' => match (true) {
                    $approved === null => 'no-lock',
                    $locked === null => 'unapproved',
                    $locked['reason'] !== $exception['reason'] => 'reason-changed',
                    $locked['fingerprint'] !== $exception['fingerprint'] => 'code-changed',
                    default => 'approved',
                },
            ];
        }

        foreach (array_diff_key($approved ?? [], $exceptions) as $target => $approval) {
            $rows[] = ['target' => (string) $target, 'reason' => $approval['reason'], 'file' => null, 'line' => null, 'status' => 'stale'];
        }

        $realignTargets = array_unique([...array_keys($requested), ...array_keys($realign->approvals())]);
        sort($realignTargets, SORT_STRING);

        foreach ($realignTargets as $target) {
            $request = $requested[$target] ?? null;
            $parts = ExceptionTargets::parse($target);
            $rows[] = [
                'target' => $target,
                'reason' => $request['reason'] ?? $realign->approvals()[$target]['reason'],
                'file' => $parts === null || str_starts_with($parts['file'], 'model:') ? null : $parts['file'],
                'line' => $request['line'] ?? null,
                'status' => $realign->status($target),
                'realign' => true,
                'step' => $request['step'] ?? null,
            ];
        }

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['ok' => true, 'lock' => $approved === null ? null : HandRolledLock::PATH, 'exceptions' => $rows]);

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf('  %s %s', $this->tag($row['status']), OutputFormatter::escape($row['target'])));
            $output->writeln('             «'.OutputFormatter::escape($row['reason']).'»'
                .($row['file'] === null ? '' : ' — '.OutputFormatter::escape($row['file']).($row['line'] === null ? '' : ':'.$row['line'])));
        }

        if ($rows === []) {
            $output->writeln('  No hay excepciones #[HandRolled] en el código ni en el candado, ni excepciones del realineamiento solicitadas.');
        }

        if ($approved === null) {
            $output->writeln("\n  Sin ".HandRolledLock::PATH.': verify las lista como aviso, como en 9.0. El dueño crea el candado con approve.');
        }

        return Command::SUCCESS;
    }

    /**
     * El objetivo exacto, o los que terminan en `\<lo escrito>`.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function resolve(string $target, array $pool): array
    {
        if (in_array($target, $pool, true)) {
            return [$target];
        }

        // Una del realineamiento, por el final de su objetivo: después de
        // `:` (el archivo), de `/` (parte de la ruta) o de `#` (el sujeto).
        return array_values(array_filter($pool, fn (string $candidate): bool => str_ends_with($candidate, '\\'.$target)
            || (ExceptionTargets::isRealign($candidate) && (str_ends_with($candidate, ':'.$target) || str_ends_with($candidate, '/'.$target) || str_ends_with($candidate, '#'.$target)))));
    }

    /**
     * @param  list<string>  $matches
     */
    private function unresolved(string $action, string $target, array $matches): string
    {
        if ($matches === []) {
            return $action === 'approve'
                ? "{$target} no es una excepción #[HandRolled] con razón del código ni una excepción del realineamiento solicitada (larapack:realign except) cuyo código siga ahí. Míralas con larapack:hand-rolled list; no se aprobó nada."
                : "{$target} no está en ".HandRolledLock::PATH.'. No se retiró nada.';
        }

        return "{$target} coincide con varias: ".implode(', ', $matches).'. Escribe la que quieras entera; no se cambió nada.';
    }

    private function tag(string $status): string
    {
        return match ($status) {
            'approved' => '<info>aprobada  </info>',
            'unapproved' => '<fg=red>pendiente </>',
            'reason-changed' => '<fg=red>cambió    </>',
            'code-changed' => '<fg=red>código    </>',
            'stale' => '<comment>sobrante  </comment>',
            default => '<comment>sin candado</comment>',
        };
    }
}
