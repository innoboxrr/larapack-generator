<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\GitHub\Response;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\NamedApproval;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\OwnerTerminal;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeGitHubApi;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeOwnerTerminal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La aprobación sin terminal sólo vale dentro del workflow de aprobación,
 * lanzado a mano, y a nombre de quien lo lanzó. Un agente se aprobó sus
 * propias excepciones nombrándose con una variable y una opción: aquí está
 * cada condición por separado, con el entorno que le falta sólo esa.
 *
 * Y la otra mitad, la que no se puede falsificar poniendo variables: lo que
 * la puerta de la CI le pregunta a GitHub de la corrida apuntada en el
 * candado (NamedApproval::verify()).
 */
final class NamedApprovalTest extends TestCase
{
    private const RUN = 'https://github.com/acme/notes/actions/runs/4242';

    private const ENV = [
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_EVENT_NAME' => 'workflow_dispatch',
        'GITHUB_REPOSITORY' => 'acme/notes',
        'GITHUB_RUN_ID' => '4242',
        'GITHUB_WORKFLOW_REF' => 'acme/notes/'.NamedApproval::WORKFLOW.'@refs/heads/feature',
        'GITHUB_ACTOR' => 'ana',
        'GITHUB_TRIGGERING_ACTOR' => 'ana',
        OwnerConfirmation::APPROVER_ENV => 'ana',
    ];

    protected function tearDown(): void
    {
        OwnerConfirmation::useTerminal(null);
        putenv('GITHUB_ACTIONS');
    }

    public function test_dentro_del_workflow_lanzado_a_mano_aprueba_quien_lo_lanzo_y_queda_la_corrida(): void
    {
        $this->assertSame(['approver' => 'ana', 'run' => self::RUN, 'error' => null], NamedApproval::fromEnvironment('ana', self::ENV));
        // El nombre, sin espacios de más ni caracteres de control alrededor.
        $this->assertSame('ana', NamedApproval::fromEnvironment("  ana\n", [OwnerConfirmation::APPROVER_ENV => " ana\t"] + self::ENV)['approver']);
        // Sin GITHUB_TRIGGERING_ACTOR, quien la lanzó es el actor.
        $this->assertSame('ana', NamedApproval::fromEnvironment('ana', ['GITHUB_TRIGGERING_ACTOR' => ''] + self::ENV)['approver']);
        $this->assertSame('ana', NamedApproval::fromEnvironment('ana', array_diff_key(self::ENV, ['GITHUB_TRIGGERING_ACTOR' => 0]))['approver']);
    }

    public function test_sin_approver_no_se_pidio_la_via_sin_terminal(): void
    {
        $this->assertSame(['approver' => null, 'run' => null, 'error' => null], NamedApproval::fromEnvironment(null, self::ENV));
        $this->assertSame(['approver' => null, 'run' => null, 'error' => null], NamedApproval::fromEnvironment(null, []));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string|false>, 2: string}>
     */
    public static function refusals(): iterable
    {
        $workflow = 'la corrida no es la del workflow de aprobación de este repositorio.';
        $run = 'la corrida no dice de qué repositorio es o cuál es.';
        $manual = 'esto no es una corrida de GitHub Actions lanzada a mano.';
        $name = 'el nombre de quien aprueba no es el de quien lanzó la corrida.';

        yield '--approver sin nombre' => ['', [], '--approver va sin nombre.'];
        yield '--approver sólo con espacios y controles' => [" \t\u{200B}", [], '--approver va sin nombre.'];
        yield 'fuera de Actions' => ['ana', ['GITHUB_ACTIONS' => 'false'], $manual];
        yield 'sin la variable de Actions' => ['ana', ['GITHUB_ACTIONS' => false], $manual];
        yield 'en un push, no a mano' => ['ana', ['GITHUB_EVENT_NAME' => 'push'], $manual];
        yield 'sin repositorio' => ['ana', ['GITHUB_REPOSITORY' => ''], $run];
        yield 'un repositorio que no es dueño/nombre' => ['ana', ['GITHUB_REPOSITORY' => 'acme'], $run];
        yield 'un repositorio con algo detrás' => ['ana', ['GITHUB_REPOSITORY' => 'acme/notes/x'], $run];
        yield 'un repositorio con algo delante' => ['ana', ['GITHUB_REPOSITORY' => 'x acme/notes'], $run];
        yield 'sin corrida' => ['ana', ['GITHUB_RUN_ID' => ''], $run];
        yield 'una corrida que no es un número' => ['ana', ['GITHUB_RUN_ID' => '42; rm -rf'], $run];
        yield 'una corrida con algo delante' => ['ana', ['GITHUB_RUN_ID' => 'x42'], $run];
        yield 'otro workflow' => ['ana', ['GITHUB_WORKFLOW_REF' => 'acme/notes/.github/workflows/ci.yml@refs/heads/feature'], $workflow];
        yield 'el workflow de aprobación de otro repositorio' => ['ana', ['GITHUB_WORKFLOW_REF' => 'otro/repo/'.NamedApproval::WORKFLOW.'@refs/heads/main'], $workflow];
        yield 'el workflow sin su @ref' => ['ana', ['GITHUB_WORKFLOW_REF' => 'acme/notes/'.NamedApproval::WORKFLOW], $workflow];
        yield 'un workflow que sólo empieza igual' => ['ana', ['GITHUB_WORKFLOW_REF' => 'acme/notes/'.NamedApproval::WORKFLOW.'.bak@refs/heads/main'], $workflow];
        yield 'sin actor' => ['ana', ['GITHUB_ACTOR' => '', 'GITHUB_TRIGGERING_ACTOR' => ''], 'quien relanzó la corrida no es quien la lanzó.'];
        yield 'relanzada por otro' => ['ana', ['GITHUB_TRIGGERING_ACTOR' => 'luis'], 'quien relanzó la corrida no es quien la lanzó.'];
        yield 'lanzada por un bot' => ['dependabot[bot]', ['GITHUB_ACTOR' => 'dependabot[bot]', 'GITHUB_TRIGGERING_ACTOR' => 'dependabot[bot]', OwnerConfirmation::APPROVER_ENV => 'dependabot[bot]'], 'la lanzó un bot, no una persona.'];
        yield 'un bot con mayúsculas' => ['Renovate[BOT]', ['GITHUB_ACTOR' => 'Renovate[BOT]', 'GITHUB_TRIGGERING_ACTOR' => 'Renovate[BOT]', OwnerConfirmation::APPROVER_ENV => 'Renovate[BOT]'], 'la lanzó un bot, no una persona.'];
        yield 'la variable nombra a otro' => ['ana', [OwnerConfirmation::APPROVER_ENV => 'luis'], $name];
        yield 'sin la variable' => ['ana', [OwnerConfirmation::APPROVER_ENV => false], $name];
        yield 'la opción nombra a otro' => ['luis', [], $name];
        yield 'la opción y la variable nombran a otro que quien la lanzó' => ['luis', [OwnerConfirmation::APPROVER_ENV => 'luis'], $name];
    }

    /**
     * @param  array<string, string|false>  $override
     */
    #[DataProvider('refusals')]
    public function test_cada_condicion_que_falta_lo_rechaza_y_dice_cual(string $option, array $override, string $why): void
    {
        $result = NamedApproval::fromEnvironment($option, $override + self::ENV);

        $this->assertNull($result['approver']);
        $this->assertNull($result['run']);
        // El rechazo no enseña la orden: manda al dueño y a la guía.
        $this->assertSame(
            'No se cambió nada: '.$why
            .' La aprobación sin terminal sólo vale dentro del workflow de aprobación, lanzado a mano por una persona en GitHub.'
            .' Si eres un agente, no lo intentes: pide al dueño del proyecto que apruebe él (larapack:realign brief le prepara la lista).'
            .' Si eres una persona, lee '.NamedApproval::DOCS.'.',
            $result['error']
        );
    }

    public function test_la_direccion_de_la_corrida_es_la_del_servidor_de_github_si_es_una(): void
    {
        $run = fn (string|false $server): ?string => NamedApproval::fromEnvironment('ana', ['GITHUB_SERVER_URL' => $server] + self::ENV)['run'];

        $this->assertSame('https://ghe.acme.test/acme/notes/actions/runs/4242', $run('https://ghe.acme.test/'));
        $this->assertSame('https://ghe.acme.test:8443/acme/notes/actions/runs/4242', $run('https://ghe.acme.test:8443'));
        // Lo que no es una dirección https limpia no acaba en el candado.
        $this->assertSame(self::RUN, $run('http://ghe.acme.test'));
        $this->assertSame(self::RUN, $run('https://ghe.acme.test/x'));
        $this->assertSame(self::RUN, $run('javascript:alert(1)//https://ghe.acme.test'));
        $this->assertSame(self::RUN, $run(''));
        $this->assertSame(self::RUN, $run(false));
    }

    public function test_sin_entorno_dado_lee_el_del_proceso(): void
    {
        putenv('GITHUB_ACTIONS=false');

        $this->assertStringContainsString('esto no es una corrida de GitHub Actions lanzada a mano.', (string) NamedApproval::fromEnvironment('ana')['error']);
        $this->assertSame(NamedApproval::fromEnvironment('ana'), OwnerConfirmation::namedApprover('ana'));
        $this->assertSame(['approver' => null, 'run' => null, 'error' => null], OwnerConfirmation::namedApprover(null));
    }

    public function test_la_puerta_comprueba_con_github_que_la_corrida_es_la_aprobacion_que_dice(): void
    {
        $run = ['path' => NamedApproval::WORKFLOW.'@refs/heads/feature', 'event' => 'workflow_dispatch', 'actor' => ['login' => 'otra'], 'triggering_actor' => ['login' => 'ana']];
        $api = fn (mixed $json, int $status = 200): FakeGitHubApi => (new FakeGitHubApi)->json('repos/acme/notes/actions/runs/4242', $json, $status);

        $github = $api($run);
        $this->assertNull(NamedApproval::verify($github, 'acme/notes', 'ana', self::RUN));
        $this->assertSame(['repos/acme/notes/actions/runs/4242'], $github->calls);
        // Sin @ref en la ruta, y sin triggering_actor (quien la lanzó es el actor).
        $this->assertNull(NamedApproval::verify($api(['path' => NamedApproval::WORKFLOW, 'actor' => ['login' => 'ana'], 'triggering_actor' => null] + $run), 'acme/notes', 'ana', self::RUN));
        // En un GitHub Enterprise, la misma corrida con otro servidor.
        $this->assertNull(NamedApproval::verify($api($run), 'acme/notes', 'ana', 'https://ghe.acme.test/acme/notes/actions/runs/4242'));

        $this->assertSame('la corrida no es del workflow de aprobación ('.NamedApproval::WORKFLOW.').', NamedApproval::verify($api(['path' => '.github/workflows/ci.yml@refs/heads/x'] + $run), 'acme/notes', 'ana', self::RUN));
        $this->assertSame('la corrida no es del workflow de aprobación ('.NamedApproval::WORKFLOW.').', NamedApproval::verify($api(['path' => null] + $run), 'acme/notes', 'ana', self::RUN));
        $this->assertSame('la corrida no se lanzó a mano (workflow_dispatch).', NamedApproval::verify($api(['event' => 'push'] + $run), 'acme/notes', 'ana', self::RUN));
        $this->assertSame('la corrida no se lanzó a mano (workflow_dispatch).', NamedApproval::verify($api(array_diff_key($run, ['event' => 0])), 'acme/notes', 'ana', self::RUN));
        // La relanzó otro: cuenta quien la disparó, no el actor original.
        $this->assertSame('la corrida la lanzó ana, no otra.', NamedApproval::verify($api($run), 'acme/notes', 'otra', self::RUN));
        $this->assertSame('la corrida la lanzó otra cuenta, no ana.', NamedApproval::verify($api(['actor' => null, 'triggering_actor' => null] + $run), 'acme/notes', 'ana', self::RUN));
        $this->assertSame('la corrida la lanzó otra cuenta, no ana.', NamedApproval::verify($api(['triggering_actor' => ['login' => 7]] + $run), 'acme/notes', 'ana', self::RUN));

        // GitHub no la da, o no da un objeto.
        $this->assertSame('GitHub no da esa corrida (HTTP 404: Not Found).', NamedApproval::verify(new FakeGitHubApi, 'acme/notes', 'ana', self::RUN));
        $this->assertSame('GitHub no da esa corrida (HTTP 200).', NamedApproval::verify($api('no es un objeto'), 'acme/notes', 'ana', self::RUN));
        $this->assertSame('GitHub no da esa corrida (HTTP 500).', NamedApproval::verify($api($run, 500), 'acme/notes', 'ana', self::RUN));
        $failing = (new FakeGitHubApi)->on('repos/acme/notes/actions/runs/4242', Response::failure('sin red'));
        $this->assertSame('GitHub no da esa corrida (sin red).', NamedApproval::verify($failing, 'acme/notes', 'ana', self::RUN));
    }

    public function test_sin_corrida_de_este_repositorio_o_sin_github_no_hay_nada_que_comprobar(): void
    {
        $github = new FakeGitHubApi;

        $this->assertSame('no dice en qué corrida se aprobó (approved_run): no hay forma de comprobar quién lo aprobó.', NamedApproval::verify($github, 'acme/notes', 'ana', null));

        $other = 'approved_run no es una corrida de este repositorio (acme/notes).';
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'https://github.com/otro/repo/actions/runs/4242'));
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'https://github.com/acme/notes/actions/runs/'));
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'https://github.com/acme/notes/actions/runs/42x'));
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'https://github.com/x/acme/notes/actions/runs/42'));
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'x https://github.com/acme/notes/actions/runs/42'));
        $this->assertSame($other, NamedApproval::verify($github, 'acme/notes', 'ana', 'http://github.com/acme/notes/actions/runs/42'));
        // El punto del nombre del repositorio es un punto, no «cualquier carácter».
        $this->assertSame('approved_run no es una corrida de este repositorio (acme/no.es).', NamedApproval::verify($github, 'acme/no.es', 'ana', 'https://github.com/acme/notes/actions/runs/42'));
        $this->assertSame('approved_run no es una corrida de este repositorio (no se sabe cuál es).', NamedApproval::verify($github, null, 'ana', self::RUN));
        $this->assertSame([], $github->calls);

        $this->assertSame('no hay con qué preguntar a GitHub (GH_TOKEN, GITHUB_TOKEN o gh) si la corrida existe.', NamedApproval::verify(null, 'acme/notes', 'ana', self::RUN));
    }

    public function test_el_workflow_de_aprobacion_se_lanza_a_mano_y_aprueba_a_nombre_de_quien_lo_lanza(): void
    {
        $workflow = NamedApproval::workflow('vendor/bin/builder', ['            - uses: actions/checkout@v5', '            - run: composer install'], 'ubuntu-latest');

        $this->assertStringStartsWith("name: LaraPack approve\n\n# Generado por `larapack:ci --approval`.", $workflow);
        $this->assertStringEndsWith(">> \"\$GITHUB_STEP_SUMMARY\"\n", $workflow);
        // Sólo a mano, y con permiso sólo para empujar la rama del candado.
        $this->assertStringContainsString("\non:\n    workflow_dispatch:\n        inputs:\n            targets:\n", $workflow);
        $this->assertStringNotContainsString('pull_request', $workflow);
        $this->assertStringContainsString("        name: LaraPack approve\n        runs-on: ubuntu-latest\n", $workflow);
        $this->assertStringContainsString("        permissions:\n            contents: write\n\n        steps:\n            - uses: actions/checkout@v5\n            - run: composer install\n            # Las entradas", $workflow);
        // Quien aprueba es quien la disparó, no un nombre libre.
        $this->assertSame(2, substr_count($workflow, 'LARAPACK_APPROVER: ${{ github.triggering_actor }}'));
        $this->assertStringContainsString('                  vendor/bin/builder larapack:hand-rolled approve "$@" --approver="$LARAPACK_APPROVER"'."\n", $workflow);
        $this->assertStringContainsString('                      set -- --all', $workflow);
        // El candado va a una rama nueva: se revisa en un PR.
        $this->assertStringContainsString('                  git add '.HandRolledLock::PATH."\n", $workflow);
        $this->assertStringContainsString('branch="larapack/approve-$GITHUB_RUN_ID"', $workflow);
        $this->assertSame('LaraPack approve', NamedApproval::JOB);
        $this->assertSame('.github/workflows/larapack-approve.yml', NamedApproval::WORKFLOW);
    }

    public function test_una_anotacion_de_actions_escapa_lo_que_romperia_la_orden(): void
    {
        $this->assertSame(
            '::warning file=.larapack/hand-rolled.lock,line=12,title=Aprobada sin terminal::La aprobó ana.',
            NamedApproval::annotation('warning', '.larapack/hand-rolled.lock', 12, 'Aprobada sin terminal', 'La aprobó ana.')
        );
        // En las propiedades, además de % y los saltos, los dos puntos y la coma.
        $this->assertSame(
            '::error file=a%2Cb%3Ac%25d.php,line=3,title=uno%3A dos%2C%0Atres%0D::100%25 seguro: a, b%0Ay c%0D',
            NamedApproval::annotation('error', 'a,b:c%d.php', 3, "uno: dos,\ntres\r", "100% seguro: a, b\ny c\r")
        );
    }

    public function test_la_frase_que_confirma_dice_el_verbo_y_cuantas(): void
    {
        $this->assertSame('apruebo 3', OwnerConfirmation::phrase('approve', 3));
        $this->assertSame('retiro 1', OwnerConfirmation::phrase('revoke', 1));
        $this->assertSame('retiro 0', OwnerConfirmation::phrase('otra cosa', 0));
        $this->assertSame('LARAPACK_APPROVER', OwnerConfirmation::APPROVER_ENV);
    }

    public function test_la_terminal_del_dueno_es_la_que_se_le_diga_en_una_prueba(): void
    {
        $terminal = new FakeOwnerTerminal('apruebo 1');
        OwnerConfirmation::useTerminal(fn (): OwnerTerminal => $terminal);

        $this->assertSame($terminal, OwnerConfirmation::openTerminal());

        // Sin terminal (un agente, la CI): null, y el comando no aprueba.
        OwnerConfirmation::useTerminal(fn (): ?OwnerTerminal => null);
        $this->assertNull(OwnerConfirmation::openTerminal());
    }

    public function test_la_terminal_ensena_lo_que_se_le_escribe_y_lee_una_linea_sin_su_salto(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertIsResource($in);
        $this->assertIsResource($out);
        fwrite($in, "apruebo 2\r\n  retiro 1  \n\nsin salto");
        rewind($in);
        $terminal = new OwnerTerminal($in, $out);

        $terminal->write('¿Apruebas? ');
        $terminal->write('escribe «apruebo 2»: ');
        rewind($out);
        $this->assertSame('¿Apruebas? escribe «apruebo 2»: ', stream_get_contents($out));

        $this->assertSame('apruebo 2', $terminal->readLine());
        // Los espacios son de quien escribe: sólo se quita el salto.
        $this->assertSame('  retiro 1  ', $terminal->readLine());
        $this->assertSame('', $terminal->readLine());
        $this->assertSame('sin salto', $terminal->readLine());
        // Fin de la entrada (una tubería que se cierra): nadie contestó.
        $this->assertNull($terminal->readLine());
    }
}
