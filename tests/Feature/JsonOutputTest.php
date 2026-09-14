<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/**
 * Con --format=json la salida es un único documento JSON y nada más.
 *
 * La guía del agente dice «parséalo, no raspes el texto», y quien lo parsea
 * decodifica la salida entera: larapack:import escribía su progreso y los avisos
 * del laraimport antes del informe, y un error llegaba como texto o como una
 * excepción sin documento. Cada comando que acepta --format=json se prueba aquí
 * al generar, al simular y al fallar.
 */
final class JsonOutputTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/laraimport.json';

    /**
     * Los generadores sin argumento.
     */
    private const PACKAGE_GENERATORS = [
        'larapack:app-service-provider',
        'larapack:auth-service-provider',
        'larapack:config',
        'larapack:event-service-provider',
        'larapack:providers',
        'larapack:route-service-provider',
    ];

    /**
     * Los generadores que reciben el nombre del modelo.
     */
    private const MODEL_GENERATORS = [
        'larapack:controller',
        'larapack:events',
        'larapack:excel',
        'larapack:export',
        'larapack:export-notification',
        'larapack:factory',
        'larapack:filters',
        'larapack:full-model',
        'larapack:migration',
        'larapack:model',
        'larapack:model-traits',
        'larapack:model-view',
        'larapack:observer',
        'larapack:pivot-migration',
        'larapack:policy',
        'larapack:react-view',
        'larapack:requests',
        'larapack:resource',
        'larapack:route',
        'larapack:test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Shop\\'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function generators(): array
    {
        $cases = [];

        foreach (self::PACKAGE_GENERATORS as $command) {
            $cases[$command] = [$command, []];
        }

        foreach (self::MODEL_GENERATORS as $command) {
            $cases[$command] = [$command, ['name' => $command === 'larapack:pivot-migration' ? 'product_tag' : 'Product']];
        }

        return $cases;
    }

    // GENERADORES

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('generators')]
    public function test_un_generador_informa_en_un_solo_documento(string $command, array $arguments): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand($command, $arguments + ['--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertFalse($report['dryRun']);
        $this->assertIsArray($report['files']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('generators')]
    public function test_un_generador_simula_en_un_solo_documento(string $command, array $arguments): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand($command, $arguments + ['--format' => 'json', '--dry-run' => true]), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertTrue($report['dryRun']);
        $this->assertNotSame([], $report['files']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('generators')]
    public function test_un_generador_falla_en_un_solo_documento(string $command, array $arguments): void
    {
        $this->assertFailureDocument($command, $arguments + ['--root' => $this->missingDirectory()], 'no existe');
    }

    public function test_un_argumento_que_falta_se_informa_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:model', [], 'name');
    }

    public function test_full_model_rechaza_only_y_except_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:full-model', ['name' => 'Product', '--only' => 'index', '--except' => 'show'], '--only');
    }

    // IMPORT

    public function test_import_informa_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => self::FIXTURE, '--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertFalse($report['dryRun']);
        $this->assertNotSame([], $report['files']);

        // Los avisos del laraimport iban como texto delante del informe; ahora van dentro.
        $this->assertNotSame([], $report['findings']);
        $this->assertSame(['warning'], array_values(array_unique(array_column($report['findings'], 'level'))));
    }

    public function test_import_simula_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => self::FIXTURE, '--format' => 'json', '--dry-run' => true]), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertTrue($report['dryRun']);
        $this->assertNotSame([], $report['files']);
        $this->assertSame([], $this->project->phpFiles());
    }

    public function test_import_de_un_laraimport_invalido_falla_en_un_solo_documento(): void
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [['props' => []]]]));

        $report = $this->assertFailureDocument('larapack:import', ['jsonPath' => $path], 'no es válido');

        $this->assertNotSame([], $report['findings']);
        $this->assertSame('error', $report['findings'][0]['level']);
        $this->assertSame([], $this->project->phpFiles());
    }

    public function test_import_de_un_archivo_que_no_existe_falla_en_un_solo_documento(): void
    {
        $report = $this->assertFailureDocument('larapack:import', ['jsonPath' => $this->missingDirectory().'/laraimport.json'], 'no es válido');

        $this->assertStringContainsString('No se encontró', $report['findings'][0]['message']);
    }

    public function test_import_con_una_raiz_que_no_existe_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:import', ['jsonPath' => self::FIXTURE, '--root' => $this->missingDirectory()], 'no existe');
    }

    // VALIDATE

    public function test_validate_informa_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:validate', ['jsonPath' => self::FIXTURE, '--format' => 'json']), $this->lastOutput);

        $this->assertTrue($this->decodeOutput()['ok']);
    }

    public function test_validate_de_un_laraimport_invalido_falla_en_un_solo_documento(): void
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [['props' => []]]]));

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:validate', ['jsonPath' => $path, '--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertFalse($report['ok']);
        $this->assertGreaterThan(0, $report['errors']);
    }

    public function test_validate_con_una_raiz_que_no_existe_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:validate', ['--root' => $this->missingDirectory()], 'no existe');
    }

    // VERIFY

    public function test_verify_informa_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:verify', ['--format' => 'json']), $this->lastOutput);

        $this->assertTrue($this->decodeOutput()['ok']);
    }

    public function test_verify_con_una_raiz_que_no_existe_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:verify', ['--root' => $this->missingDirectory()], 'no existe');
    }

    // AUDIT

    public function test_audit_informa_en_un_solo_documento(): void
    {
        $this->runCommand('larapack:audit', ['path' => $this->project->path, '--format' => 'json']);

        $report = $this->decodeOutput();

        $this->assertIsBool($report['ok']);
        $this->assertIsArray($report['findings']);
    }

    public function test_audit_de_una_ruta_que_no_existe_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:audit', ['path' => $this->missingDirectory()], 'no existe');
    }

    // NEW

    public function test_new_informa_en_un_solo_documento(): void
    {
        $target = $this->project->path.'/shop';

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', ['name' => 'acme/shop', 'directory' => $target, '--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertFalse($report['dryRun']);
        $this->assertContains('composer.json', array_column($report['files'], 'file'));
    }

    public function test_new_simula_en_un_solo_documento(): void
    {
        $target = $this->project->path.'/shop';

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', ['name' => 'acme/shop', 'directory' => $target, '--format' => 'json', '--dry-run' => true]), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['ok']);
        $this->assertTrue($report['dryRun']);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_new_con_un_nombre_invalido_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:new', ['name' => 'Acme/Shop', 'directory' => $this->project->path.'/shop'], 'no es un nombre de paquete válido');
    }

    public function test_new_sobre_un_proyecto_existente_falla_en_un_solo_documento(): void
    {
        $this->assertFailureDocument('larapack:new', ['name' => 'acme/shop', 'directory' => $this->project->path], 'ya tiene composer.json');
    }

    // AYUDAS

    /**
     * Ejecuta el comando con --format=json, espera que falle y devuelve el
     * documento de error.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function assertFailureDocument(string $command, array $arguments, string $error): array
    {
        $this->assertSame(Command::FAILURE, $this->runCommand($command, $arguments + ['--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertFalse($report['ok']);
        $this->assertIsString($report['error']);
        $this->assertStringContainsString($error, $report['error']);

        return $report;
    }

    /**
     * La salida entera, decodificada: así la lee un agente.
     *
     * @return array<string, mixed>
     */
    private function decodeOutput(): array
    {
        try {
            $decoded = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail("Con --format=json la salida no es un único documento JSON ({$e->getMessage()}):\n{$this->lastOutput}");
        }

        $this->assertIsArray($decoded, "Con --format=json la salida no es un objeto JSON:\n{$this->lastOutput}");

        return $decoded;
    }

    private function missingDirectory(): string
    {
        return $this->project->path.'/no-existe';
    }
}
