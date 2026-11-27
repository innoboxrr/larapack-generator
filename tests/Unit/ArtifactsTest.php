<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Commands\MakeFullModelCommand;
use Innoboxrr\LarapackGenerator\Commands\MakeProvidersCommand;
use Innoboxrr\LarapackGenerator\Commands\RemoveFullModelCommand;
use Innoboxrr\LarapackGenerator\Tools\Artifacts;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\PackageArtifact;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Las listas de herramientas de full-model, remove-full-model y providers son
 * nombres. Cada uno tiene que resolver a una clase con su contrato: uno que no
 * lo hiciera se saltaba en silencio y esa pieza no se generaba ni se borraba.
 * Las fotos lo cazaban para las herramientas que ya tenían foto; esto, para
 * cualquiera que se añada a una lista.
 */
final class ArtifactsTest extends TestCase
{
    /**
     * Lo de las listas, y lo que añaden --vue, --react y --metas.
     *
     * @return array<string, array{0: string}>
     */
    public static function modelTools(): array
    {
        $names = [
            ...self::listOf(MakeFullModelCommand::class),
            ...self::listOf(RemoveFullModelCommand::class),
            'ModelView',
            'ReactView',
            'ModelMetas',
        ];

        $cases = [];

        foreach (array_unique($names) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providerTools(): array
    {
        $cases = [];

        foreach (self::listOf(MakeProvidersCommand::class) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('modelTools')]
    public function test_cada_herramienta_de_modelo_de_las_listas_es_un_model_artifact(string $name): void
    {
        $this->assertInstanceOf(ModelArtifact::class, Artifacts::model($name));
    }

    #[DataProvider('providerTools')]
    public function test_cada_proveedor_de_la_lista_es_un_package_artifact(string $name): void
    {
        $this->assertInstanceOf(PackageArtifact::class, Artifacts::provider($name));
    }

    public function test_un_nombre_que_no_existe_falla(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('«Ghost»');
        $this->expectExceptionMessage('no existe');

        Artifacts::model('Ghost');
    }

    /**
     * La clase existe, pero no cumple el contrato: es el caso que antes se
     * saltaba sin decir nada (quitarle el `implements` a una herramienta).
     */
    public function test_una_herramienta_sin_el_contrato_falla(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no implementa '.ModelArtifact::class);

        Artifacts::model('PivotMigration');
    }

    public function test_un_proveedor_que_no_existe_falla(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no existe');

        Artifacts::provider('Ghost');
    }

    /**
     * La clase existe donde se busca, pero no implementa PackageArtifact.
     */
    public function test_un_proveedor_sin_el_contrato_falla(): void
    {
        require_once dirname(__DIR__).'/Fixtures/artifacts/NotAnArtifactServiceProviderTool.php';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no implementa '.PackageArtifact::class);

        Artifacts::provider('NotAnArtifact');
    }

    /**
     * @param  class-string  $command
     * @return list<string>
     */
    private static function listOf(string $command): array
    {
        $list = (new ReflectionProperty($command, 'commands'))->getDefaultValue();

        self::assertIsArray($list);

        return array_values(array_filter($list, 'is_string'));
    }
}
