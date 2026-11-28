<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\GitHub\Api;

/**
 * La aprobación sin terminal: la de una persona que lanza a mano el workflow
 * de aprobación de GitHub Actions que genera `larapack:ci --approval`.
 *
 * Hasta aquí bastaba nombrarse con `LARAPACK_APPROVER` y `--approver`, en
 * cualquier sitio. En una prueba de autonomía real, un agente sin la guardia
 * instalada se aprobó sus propias excepciones así —y el propio mensaje de
 * rechazo le había enseñado la orden—. Nombrarse no prueba nada: el nombre lo
 * escribe quien corre la orden.
 *
 * Ahora la vía sólo vale **dentro de ese workflow**, disparado a mano
 * (`workflow_dispatch`), y quien aprueba es quien lo disparó
 * (`github.triggering_actor`), no un nombre libre:
 *
 *  - `GITHUB_ACTIONS=true` y `GITHUB_EVENT_NAME=workflow_dispatch`;
 *  - `GITHUB_WORKFLOW_REF` es el de WORKFLOW en este repositorio;
 *  - `LARAPACK_APPROVER`, `--approver` y el actor que disparó la corrida son
 *    el mismo, y no es un bot (`…[bot]`); una corrida que relanza otro
 *    (`GITHUB_TRIGGERING_ACTOR` distinto de `GITHUB_ACTOR`) no vale;
 *  - la corrida se apunta en el candado (`approved_run`), junto al nombre.
 *
 * Por qué así y no con un secreto o una firma: el secreto viviría en el
 * mismo entorno que el agente (la variable del job, el llavero de quien lo
 * lanza) o habría que repartir y rotar claves, y la identidad de quien
 * dispara una corrida ya la da GitHub, que no se puede falsificar desde
 * fuera: un `workflow_dispatch` sólo lo lanza quien tiene permiso de
 * escritura, y su `triggering_actor` es esa cuenta.
 *
 * Lo que NO garantiza, dicho: las variables `GITHUB_*` se pueden poner a mano
 * fuera de Actions. Por eso la aprobación lleva la corrida (`approved_run`) y
 * el job de la puerta (`larapack:hand-rolled list --format=github`, que
 * genera `larapack:ci`) pregunta a GitHub por cada una: que exista, que sea
 * de este workflow, lanzada a mano y por quien dice `approved_by`. Una
 * inventada, o de otra persona, es un error en el PR y el check no pasa. Y
 * una cuenta con permiso para lanzar workflows (un token que el agente tenga
 * con `actions: write`) lanza la corrida a su nombre: la capa que no se salta
 * sigue siendo CODEOWNERS sobre el candado y la revisión de su diff.
 *
 * Tampoco dice QUÉ aprobó la corrida: GitHub da quién la lanzó y cómo acabó,
 * no qué entradas escribió. Una corrida de verdad, de esa persona y que
 * terminó bien, citada en otra entrada que ella no aprobó, pasa esta
 * comprobación; lo que la frena es que esa entrada es una línea nueva del
 * candado en el diff que revisa su dueño. Una corrida fallida o cancelada sí
 * se rechaza: no aprobó nada.
 */
final class NamedApproval
{
    /**
     * El workflow de aprobación. Lo cubre CODEOWNERS (CiGate::ownedPaths()):
     * si cualquiera pudiera editarlo, la corrida aprobaría lo que el cambio
     * quisiera.
     */
    public const WORKFLOW = '.github/workflows/larapack-approve.yml';

    /**
     * El nombre del job del workflow de aprobación.
     */
    public const JOB = 'LaraPack approve';

    /**
     * Dónde se explica a una persona cómo aprobar sin terminal. Es lo que
     * enseñan los rechazos, en lugar de la orden lista para copiar.
     */
    public const DOCS = 'docs/realign/exceptions.md#aprobar-sin-terminal-el-workflow-de-aprobación';

    /**
     * Quién aprueba sin terminal y en qué corrida, si se dio como se pide.
     * approver null y error null: no se pidió la vía sin terminal (sin
     * `--approver`).
     *
     * @param  array<string, string|false>|null  $env  null, el entorno del proceso
     * @return array{approver: string|null, run: string|null, error: string|null}
     */
    public static function fromEnvironment(?string $option, ?array $env = null): array
    {
        if ($option === null) {
            return ['approver' => null, 'run' => null, 'error' => null];
        }

        $read = function (string $name) use ($env): string {
            $value = $env === null ? getenv($name) : ($env[$name] ?? false);

            return is_string($value) ? self::clean($value) : '';
        };

        $refused = fn (string $why): array => ['approver' => null, 'run' => null, 'error' => 'No se cambió nada: '.$why
            .' La aprobación sin terminal sólo vale dentro del workflow de aprobación, lanzado a mano por una persona en GitHub. '
            .'Si eres un agente, no lo intentes: pide al dueño del proyecto que apruebe él (larapack:realign brief le prepara la lista). '
            .'Si eres una persona, lee '.self::DOCS.'.'];

        $option = self::clean($option);

        if ($option === '') {
            return $refused('--approver va sin nombre.');
        }

        if ($read('GITHUB_ACTIONS') !== 'true' || $read('GITHUB_EVENT_NAME') !== 'workflow_dispatch') {
            return $refused('esto no es una corrida de GitHub Actions lanzada a mano.');
        }

        $repository = $read('GITHUB_REPOSITORY');
        $runId = $read('GITHUB_RUN_ID');

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1 || preg_match('/^[0-9]+$/', $runId) !== 1) {
            return $refused('la corrida no dice de qué repositorio es o cuál es.');
        }

        if (! str_starts_with($read('GITHUB_WORKFLOW_REF'), $repository.'/'.self::WORKFLOW.'@')) {
            return $refused('la corrida no es la del workflow de aprobación de este repositorio.');
        }

        $actor = $read('GITHUB_ACTOR');
        $triggering = $read('GITHUB_TRIGGERING_ACTOR');
        $triggering = $triggering === '' ? $actor : $triggering;

        if ($actor === '' || $triggering !== $actor) {
            return $refused('quien relanzó la corrida no es quien la lanzó.');
        }

        if (str_ends_with(strtolower($actor), '[bot]')) {
            return $refused('la lanzó un bot, no una persona.');
        }

        if ($read(OwnerConfirmation::APPROVER_ENV) !== $actor || $option !== $actor) {
            return $refused('el nombre de quien aprueba no es el de quien lanzó la corrida.');
        }

        $server = rtrim($read('GITHUB_SERVER_URL'), '/');
        $server = preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?$#', $server) === 1 ? $server : 'https://github.com';

        return ['approver' => $actor, 'run' => "{$server}/{$repository}/actions/runs/{$runId}", 'error' => null];
    }

    /**
     * Si la corrida apuntada en el candado es de verdad la aprobación que
     * dice: null si sí; si no, por qué.
     */
    public static function verify(?Api $api, ?string $repository, string $approver, ?string $run): ?string
    {
        if ($run === null) {
            return 'no dice en qué corrida se aprobó (approved_run): no hay forma de comprobar quién lo aprobó.';
        }

        if ($repository === null || preg_match('#^https://[^/]+/'.preg_quote($repository, '#').'/actions/runs/([0-9]+)$#', $run, $match) !== 1) {
            return 'approved_run no es una corrida de este repositorio'.($repository === null ? ' (no se sabe cuál es)' : " ({$repository})").'.';
        }

        if ($api === null) {
            return 'no hay con qué preguntar a GitHub (GH_TOKEN, GITHUB_TOKEN o gh) si la corrida existe.';
        }

        $response = $api->get("repos/{$repository}/actions/runs/{$match[1]}");

        if (! $response->ok() || ! is_array($response->json)) {
            return 'GitHub no da esa corrida ('.$response->describe().').';
        }

        $json = $response->json;
        $path = is_string($json['path'] ?? null) ? explode('@', $json['path'])[0] : null;
        $event = $json['event'] ?? null;
        $by = $json['triggering_actor']['login'] ?? ($json['actor']['login'] ?? null);

        if ($path !== self::WORKFLOW) {
            return 'la corrida no es del workflow de aprobación ('.self::WORKFLOW.').';
        }

        if ($event !== 'workflow_dispatch') {
            return 'la corrida no se lanzó a mano (workflow_dispatch).';
        }

        if (! is_string($by) || $by !== $approver) {
            return 'la corrida la lanzó '.(is_string($by) ? $by : 'otra cuenta').', no '.$approver.'.';
        }

        // Una corrida que falló, se canceló o sigue en marcha no aprobó
        // nada: citarla es apuntarse la intención de otro.
        if (($json['status'] ?? null) !== 'completed' || ($json['conclusion'] ?? null) !== 'success') {
            return 'la corrida no terminó bien ('.self::clean(is_string($json['conclusion'] ?? null) ? $json['conclusion'] : (is_string($json['status'] ?? null) ? $json['status'] : 'sin conclusión')).'): no aprobó nada.';
        }

        return null;
    }

    /**
     * El workflow de aprobación.
     *
     * Se lanza a mano en la pestaña Actions, sobre la rama con las
     * solicitudes; aprueba lo que se le pide a nombre de quien lo lanzó y
     * empuja el candado a una rama nueva (`larapack/approve-<corrida>`), de la
     * que la persona abre un PR: el candado se revisa como siempre.
     *
     * @param  list<string>  $setup  los pasos que preparan PHP y Composer (CiGate)
     * @param  string  $runsOn  el `runs-on` ya escrito en YAML
     */
    public static function workflow(string $binary, array $setup, string $runsOn): string
    {
        return implode("\n", [
            'name: LaraPack approve',
            '',
            '# Generado por `larapack:ci --approval`. La aprobación sin terminal de las',
            '# excepciones (larapack:hand-rolled approve): la lanza a mano una persona,',
            '# en Actions, sobre la rama con las solicitudes, y queda a su nombre',
            '# (github.triggering_actor) y con esta corrida en .larapack/hand-rolled.lock.',
            '# El candado se empuja a una rama nueva y se revisa en un PR, como siempre.',
            '# Lo cubre CODEOWNERS: un cambio aquí lo revisa el dueño.',
            'on:',
            '    workflow_dispatch:',
            '        inputs:',
            '            targets:',
            "                description: 'Qué aprobar, uno por línea, como lo da larapack:hand-rolled list (vacío si se marca all)'",
            '                required: false',
            '                default: \'\'',
            '            all:',
            "                description: 'Aprobar todas las excepciones pedidas (léelas antes)'",
            '                type: boolean',
            '                default: false',
            '',
            'jobs:',
            '    approve:',
            '        name: '.self::JOB,
            '        runs-on: '.$runsOn,
            '        # Escribe una rama nueva con el candado; nada más.',
            '        permissions:',
            '            contents: write',
            '',
            '        steps:',
            ...$setup,
            '            # Las entradas van por el entorno, no dentro de la orden: un objetivo',
            '            # lleva comillas y paréntesis.',
            '            - name: Aprobar',
            '              env:',
            '                  LARAPACK_APPROVER: ${{ github.triggering_actor }}',
            '                  TARGETS: ${{ inputs.targets }}',
            '                  ALL: ${{ inputs.all }}',
            '              run: |',
            '                  if [ "$ALL" = "true" ]; then',
            '                      set -- --all',
            '                  else',
            '                      mapfile -t targets < <(printf \'%s\n\' "$TARGETS" | sed \'/^[[:space:]]*$/d\')',
            '                      set -- "${targets[@]}"',
            '                  fi',
            "                  {$binary} larapack:hand-rolled approve \"\$@\" --approver=\"\$LARAPACK_APPROVER\"",
            '',
            '            - name: Empujar el candado a una rama nueva',
            '              env:',
            '                  LARAPACK_APPROVER: ${{ github.triggering_actor }}',
            '              run: |',
            '                  branch="larapack/approve-$GITHUB_RUN_ID"',
            '                  git config user.name "github-actions[bot]"',
            '                  git config user.email "41898282+github-actions[bot]@users.noreply.github.com"',
            '                  git switch -c "$branch"',
            '                  git add '.HandRolledLock::PATH,
            '                  git commit -m "Excepciones aprobadas por $LARAPACK_APPROVER (corrida $GITHUB_RUN_ID)"',
            '                  git push origin "$branch"',
            '                  echo "Abre un PR desde $branch: $GITHUB_SERVER_URL/$GITHUB_REPOSITORY/compare/$GITHUB_REF_NAME...$branch" >> "$GITHUB_STEP_SUMMARY"',
        ])."\n";
    }

    /**
     * Una anotación de Actions sobre una línea de un archivo: sale en el
     * diff del PR, junto a la línea.
     */
    public static function annotation(string $level, string $file, int $line, string $title, string $message): string
    {
        $property = fn (string $value): string => str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);

        return "::{$level} file={$property($file)},line={$line},title={$property($title)}::"
            .str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
    }

    /**
     * Un nombre en una línea, sin caracteres de control.
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', mb_scrub($value, 'UTF-8')));
    }
}
