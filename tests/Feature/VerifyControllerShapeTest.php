<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;

/**
 * La forma de los controladores escritos a mano.
 *
 * Los agentes respetan `return $request->handle();` en lo generado y lo
 * pierden en lo que el generador no sabe hacer: un asistente, una ruta
 * pública, un webhook. El fixture reúne esos casos —ninguno está en un
 * manifiesto— y la aplicación tiene además un paquete local en packages/.
 */
final class VerifyControllerShapeTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/controller-shape';

    protected function setUp(): void
    {
        parent::setUp();

        $project = $this->useProject(FakeProject::empty());
        $this->copy(self::FIXTURE, $project->path);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + $options);

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check, ?string $file = null): array
    {
        return array_values(array_filter(
            $report['findings'],
            fn (array $f): bool => $f['check'] === $check && ($file === null || $f['file'] === $file)
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function messagesOf(array $report, string $check, string $file): array
    {
        return array_column($this->findingsOf($report, $check, $file), 'message');
    }

    public function test_corre_sin_manifiesto(): void
    {
        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame('empty-manifest', $report['findings'][0]['check']);
        $this->assertNotEmpty($this->findingsOf($report, 'controller-shape'));
    }

    public function test_un_controlador_que_delega_no_tiene_hallazgos(): void
    {
        [, $report] = $this->verify();

        $files = array_column($report['findings'], 'file');

        // Incluye el modelo enlazado como argumento con nombre, la request que
        // hereda handle() del padre, la que lo trae de un trait, el
        // constructor, el middleware() estático y un método protegido.
        $this->assertNotContains('app/Http/Controllers/CleanController.php', $files);
        $this->assertNotContains('app/Http/Controllers/InvokableController.php', $files);
        $this->assertNotContains('app/Http/Controllers/Controller.php', $files);
        $this->assertNotContains('app/Http/Controllers/Concerns/HasMiddlewareOnly.php', $files);
    }

    public function test_un_controlador_gordo_es_error(): void
    {
        [, $report] = $this->verify();

        $messages = $this->messagesOf($report, 'controller-shape', 'app/Http/Controllers/FatController.php');

        $this->assertCount(3, $messages);
        $this->assertStringContainsString('FatController::store() (línea 16) no delega', $messages[0]);
        $this->assertStringContainsString('pasa a handle() algo que no es una variable', $messages[1]);
        $this->assertStringContainsString('cuerpo vacío', $messages[2]);
        $this->assertSame('error', $this->findingsOf($report, 'controller-shape')[0]['level']);
    }

    public function test_validar_en_el_controlador_es_error(): void
    {
        [, $report] = $this->verify();

        $messages = $this->messagesOf($report, 'controller-shape', 'app/Http/Controllers/ValidatesController.php');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ValidatesController::__invoke()', $messages[0]);
        $this->assertStringContainsString('recibe Illuminate\Http\Request', $messages[0]);
        $this->assertStringContainsString('validate()', $messages[0]);
    }

    public function test_delegar_en_una_request_base_es_error(): void
    {
        [, $report] = $this->verify();

        $messages = $this->messagesOf($report, 'controller-shape', 'app/Http/Controllers/BaseRequestController.php');

        $this->assertCount(3, $messages);
        $this->assertStringContainsString('tipa $request con Illuminate\Http\Request', $messages[0]);
        $this->assertStringContainsString('tipa $request con Illuminate\Foundation\Http\FormRequest', $messages[1]);
        $this->assertStringContainsString('no tipa $request con un FormRequest', $messages[2]);
    }

    public function test_hand_rolled_con_razon_excusa_y_se_lista(): void
    {
        [, $report] = $this->verify();

        $this->assertSame([], $this->findingsOf($report, 'controller-shape', 'app/Http/Controllers/WebhookController.php'));

        $listed = $this->findingsOf($report, 'hand-rolled', 'app/Http/Controllers/WebhookController.php');

        $this->assertCount(1, $listed);
        $this->assertSame('info', $listed[0]['level']);
        // La razón partida en dos literales se lee entera.
        $this->assertStringContainsString('cuerpo en bruto: un FormRequest lo decodifica', $listed[0]['message']);

        $method = $this->messagesOf($report, 'hand-rolled', 'app/Http/Controllers/TokenController.php');

        $this->assertStringContainsString('TokenController::issue()', $method[0]);
        $this->assertStringContainsString('RFC 6749', $method[0]);
    }

    public function test_hand_rolled_sin_razon_es_error_y_no_excusa(): void
    {
        [, $report] = $this->verify();

        $errors = array_values(array_filter(
            $this->findingsOf($report, 'hand-rolled', 'app/Http/Controllers/TokenController.php'),
            fn (array $f): bool => $f['level'] === 'error'
        ));

        // Sin argumentos y con una razón en blanco, por alias del espacio de nombres.
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('revoke()', $errors[0]['message']);
        $this->assertStringContainsString('introspect()', $errors[1]['message']);

        $shape = $this->messagesOf($report, 'controller-shape', 'app/Http/Controllers/TokenController.php');

        $this->assertCount(2, $shape);
        $this->assertStringNotContainsString('issue()', implode("\n", $shape));
    }

    public function test_la_request_sin_handle_es_error_una_vez(): void
    {
        [, $report] = $this->verify();

        $missing = $this->findingsOf($report, 'request-handle', 'app/Http/Requests/Post/NoHandleRequest.php');

        // Dos acciones delegan en ella: un hallazgo, donde hay que arreglarlo.
        $this->assertCount(1, $missing);
        $this->assertSame('error', $missing[0]['level']);
        $this->assertStringContainsString('MissingHandleController::index()', $missing[0]['message']);
    }

    public function test_una_request_de_fuera_del_proyecto_se_informa_sin_adivinar(): void
    {
        [, $report] = $this->verify();

        $unknown = $this->findingsOf($report, 'request-handle', 'app/Http/Controllers/MissingHandleController.php');

        $this->assertCount(1, $unknown);
        $this->assertSame('info', $unknown[0]['level']);
        $this->assertStringContainsString('Vendor\Package\Http\Requests\VendorRequest', $unknown[0]['message']);
    }

    public function test_un_trait_con_logica_en_http_controllers(): void
    {
        [, $report] = $this->verify();

        $used = $this->findingsOf($report, 'controller-concern', 'app/Http/Controllers/Concerns/AuthorizesInScope.php');

        $this->assertCount(1, $used);
        $this->assertSame('error', $used[0]['level']);
        $this->assertStringContainsString('lo usa FatController', $used[0]['message']);

        // Sólo lo usa una request: la lógica está donde va; el trait, no.
        $misplaced = $this->findingsOf($report, 'controller-concern', 'app/Http/Controllers/Concerns/FindsRows.php');

        $this->assertCount(1, $misplaced);
        $this->assertSame('warning', $misplaced[0]['level']);
        $this->assertStringContainsString('Http/Requests/Concerns', $misplaced[0]['message']);
    }

    public function test_revisa_los_paquetes_locales(): void
    {
        [, $report] = $this->verify();

        $messages = $this->messagesOf($report, 'controller-shape', 'packages/billing/src/Http/Controllers/InvoiceController.php');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('InvoiceController::index()', $messages[0]);
    }

    public function test_check_corre_solo_lo_pedido_y_sigue_siendo_un_documento(): void
    {
        [$code, $report] = $this->verify(['--check' => ['controller-concern', 'request-handle']]);

        $this->assertIsArray($report, 'La salida tiene que ser un único documento JSON.');
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['controller-concern', 'request-handle'], $report['checks']);
        $this->assertSame(
            ['controller-concern', 'request-handle'],
            array_values(array_unique(array_column($report['findings'], 'check')))
        );
        $this->assertSame(2, $report['errors']);
        $this->assertSame(1, $report['warnings']);
    }

    public function test_check_sin_hallazgos_pasa_aunque_haya_otros(): void
    {
        [$code, $report] = $this->verify(['--check' => ['missing-file']]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['findings']);
    }

    /**
     * Un nombre mal escrito no puede parecer un verde.
     */
    public function test_check_desconocido_falla(): void
    {
        [$code, $report] = $this->verify(['--check' => ['controller-shap']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('controller-shap', $report['error']);
        $this->assertStringContainsString('controller-shape', $report['error']);
    }

    private function copy(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }
}
