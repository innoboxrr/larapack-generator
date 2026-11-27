<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El `User` de Laravel: la aplicación ya trae su migración de `users` y su
 * `UserFactory`, y el generador, a propósito, no los toca —la migración la
 * salta diciendo «no la generó LaraPack», la factory porque ya existe—. Así
 * que el modelo queda en el manifiesto sin esos dos componentes, y
 * `inconsistent-entity` lo comparaba con sus pares y avisaba de que «no
 * tiene componente Migration y todas las demás entidades sí». En
 * school-management eran los dos únicos avisos de esa comprobación: un falso
 * positivo que había que aprender a ignorar.
 *
 * El laraimport de la prueba es el de school-management (`User` y un par suyo
 * con la misma forma, `ProviderModuleGrant`), y la migración y la factory son
 * las que trae ese proyecto.
 */
final class VerifyFrameworkUserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/real-projects/school-management';

    private const FACTORY = 'database/factories/UserFactory.php';

    private const MIGRATION = 'database/migrations/0001_01_01_000000_create_users_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());

        foreach ([self::FACTORY, self::MIGRATION] as $file) {
            @mkdir(dirname($this->project->path.'/'.$file), 0777, true);
            copy(self::FIXTURES.'/'.$file, $this->project->path.'/'.$file);
        }

        copy(self::FIXTURES.'/laraimport.user.json', $this->project->path.'/laraimport.json');

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import'), $this->lastOutput);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inconsistent(): array
    {
        $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['inconsistent-entity']]);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return $report['findings'];
    }

    public function test_el_generador_deja_al_proyecto_su_migracion_y_su_factory(): void
    {
        $this->assertSame(
            file_get_contents(self::FIXTURES.'/'.self::FACTORY),
            $this->project->read(self::FACTORY)
        );
        $this->assertSame(
            [basename(self::MIGRATION)],
            array_map('basename', glob($this->project->path.'/database/migrations/*_create_users_table.php') ?: [])
        );
    }

    public function test_el_user_de_laravel_no_es_una_entidad_incompleta(): void
    {
        $this->assertSame([], $this->inconsistent());
    }

    /**
     * Lo que se reconoce es el archivo, no el nombre `User`: si la factory del
     * proyecto desaparece, el componente falta de verdad y se avisa.
     */
    public function test_si_el_componente_del_proyecto_desaparece_se_avisa(): void
    {
        unlink($this->project->path.'/'.self::FACTORY);

        $findings = $this->inconsistent();

        $this->assertSame(['User'], array_values(array_unique(array_column($findings, 'model'))));
        $this->assertStringContainsString('Factory', implode(' ', array_column($findings, 'message')));
        $this->assertStringNotContainsString('Migration', implode(' ', array_column($findings, 'message')));
    }

    /**
     * Con `table_prefix` la tabla es la del `$table` del modelo, no la que se
     * deduce de su nombre: una migración de la tabla sin prefijo es de otra
     * tabla y no cuenta.
     */
    public function test_con_prefijo_se_busca_la_migracion_de_la_tabla_prefijada(): void
    {
        $this->project->cleanup();
        $this->useProject(FakeProject::library());

        file_put_contents($this->project->path.'/laraimport.json', json_encode([
            'table_prefix' => 'shop',
            'models' => [
                ['name' => 'Product', 'props' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Tag', 'props' => [['name' => 'name', 'type' => 'string']]],
            ],
        ]));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import'), $this->lastOutput);

        // El proyecto se queda la migración de Tag: deja de estar registrada.
        $manifestFile = $this->project->path.'/.larapack/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $migration = null;

        foreach ($manifest['models']['Tag']['files'] as $file => $meta) {
            if ($meta['stub'] === 'Migration/MigrationTemplate.txt') {
                $migration = $file;
                unset($manifest['models']['Tag']['files'][$file]);
            }
        }

        file_put_contents($manifestFile, json_encode($manifest));

        $this->assertSame(1, preg_match('/_create_shop_tags_table\.php$/', (string) $migration), (string) $migration);
        $this->assertSame([], $this->inconsistent());

        rename(
            $this->project->path.'/'.$migration,
            $this->project->path.'/'.str_replace('create_shop_tags', 'create_tags', (string) $migration)
        );

        $this->assertSame(['Tag'], array_values(array_unique(array_column($this->inconsistent(), 'model'))));
    }

    public function test_y_si_desaparece_la_migracion_tambien(): void
    {
        unlink($this->project->path.'/'.self::MIGRATION);

        $findings = $this->inconsistent();

        $this->assertSame(['User'], array_values(array_unique(array_column($findings, 'model'))));
        $this->assertStringContainsString('Migration', implode(' ', array_column($findings, 'message')));
    }
}
