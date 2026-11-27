<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lo nuevo del contrato (9.4.0) se valida antes de escribir nada: una medida
 * en un tipo que no la admite, un índice sobre una columna que no existe, dos
 * modelos con la misma tabla o un nombre de ruta repetido acabarían en una
 * migración que falla o en una ruta que el front no puede llamar.
 */
final class ContractExtensionsValidationTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): array
    {
        $model = fn (array $props, array $extra = []): array => ['models' => [['name' => 'Product', 'props' => $props] + $extra]];

        return [
            'length en un entero' => [$model([['name' => 'stock', 'type' => 'integer', 'length' => 5]]), '/models/0/props/0/length'],
            'precision en un string' => [$model([['name' => 'code', 'type' => 'string', 'precision' => 5]]), '/models/0/props/0/precision'],
            'scale sin precision' => [$model([['name' => 'price', 'type' => 'decimal', 'scale' => 2]]), '/models/0/props/0/scale'],
            'scale mayor que precision' => [$model([['name' => 'price', 'type' => 'decimal', 'precision' => 4, 'scale' => 6]]), '/models/0/props/0/scale'],
            'use_current en un string' => [$model([['name' => 'code', 'type' => 'string', 'use_current' => true]]), '/models/0/props/0/use_current'],
            'use_current con default' => [$model([['name' => 'seen_at', 'type' => 'timestamp', 'use_current' => true, 'default' => '2020-01-01']]), '/models/0/props/0/use_current'],
            'índice sobre una columna que no existe' => [$model([['name' => 'code', 'type' => 'string']], ['indexes' => [['columns' => ['sku']]]]), '/models/0/indexes/0/columns'],
            'índice repetido' => [$model([['name' => 'code', 'type' => 'string']], ['indexes' => [['columns' => ['code']], ['columns' => ['code']]]]), '/models/0/indexes/1'],
            'dos índices con el mismo nombre' => [$model([['name' => 'code', 'type' => 'string'], ['name' => 'sku', 'type' => 'string']], ['indexes' => [['columns' => ['code'], 'name' => 'x'], ['columns' => ['sku'], 'name' => 'x']]]), '/models/0/indexes/1/name'],
            'dos modelos con la misma tabla' => [['models' => [
                ['name' => 'Person', 'table' => 'people', 'props' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Human', 'table' => 'people', 'props' => [['name' => 'name', 'type' => 'string']]],
            ]], '/models/1/table'],
            'tabla declarada que deduce otro modelo' => [['models' => [
                ['name' => 'Person', 'props' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Human', 'table' => 'people', 'props' => [['name' => 'name', 'type' => 'string']]],
            ]], '/models/1/table'],
            'route_name repetido' => [['models' => [['name' => 'Item', 'props' => [['name' => 'name', 'type' => 'string']]]], 'controllers' => [['name' => 'Desk', 'endpoints' => [
                ['name' => 'one', 'method' => 'get', 'route_name' => 'same', 'authorize' => 'authenticated'],
                ['name' => 'two', 'method' => 'post', 'route_name' => 'same', 'authorize' => 'authenticated'],
            ]]]], '/controllers/0/endpoints/1/route_name'],
            'route_name de una acción CRUD' => [$model([['name' => 'name', 'type' => 'string']], ['endpoints' => [['name' => 'publish', 'method' => 'post', 'route_name' => 'update']]]), '/models/0/endpoints/0/route_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    #[DataProvider('invalid')]
    public function test_lo_que_no_tiene_sentido_se_rechaza_con_su_ruta(array $raw, string $path): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray($raw);

        $this->assertNull($document, 'Se aceptó: '.json_encode($errors));
        $this->assertContains($path, array_column(array_filter($errors, fn (array $e): bool => $e['level'] === SemanticValidator::ERROR), 'path'), json_encode($errors, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_un_belongs_to_sigue_exigiendo_related_y_un_morph_to_no(): void
    {
        $relation = fn (array $relation): array => ['models' => [['name' => 'Comment', 'props' => [['name' => 'commentable', 'type' => 'morphs']], 'load_relations' => [$relation]]]];

        ['document' => $morph, 'errors' => $morphErrors] = ImportDocument::fromArray($relation(['type' => 'morphTo', 'name' => 'commentable']));
        $this->assertNotNull($morph, json_encode($morphErrors) ?: '');

        ['document' => $belongs] = ImportDocument::fromArray($relation(['type' => 'belongsTo', 'name' => 'post']));
        $this->assertNull($belongs, 'Un belongsTo sin related no puede generarse.');
    }

    /**
     * Una URI CRUD sólo tapa la ruta CRUD que existe: un controllers[] no
     * tiene ninguna, y un modelo sin la acción tampoco. Es lo que deja
     * adoptar `…/create` escrito a mano conservando su URI.
     */
    public function test_una_uri_crud_solo_choca_con_la_accion_que_existe(): void
    {
        $controller = ['models' => [['name' => 'Item', 'props' => [['name' => 'name', 'type' => 'string']]]], 'controllers' => [['name' => 'Desk', 'endpoints' => [
            ['name' => 'store', 'method' => 'post', 'uri' => 'create', 'authorize' => 'authenticated'],
        ]]]];
        $this->assertNotNull(ImportDocument::fromArray($controller)['document']);

        $withoutCreate = ['models' => [['name' => 'Item', 'props' => [['name' => 'name', 'type' => 'string']], 'routes' => ['except' => ['create']],
            'endpoints' => [['name' => 'store', 'method' => 'post', 'uri' => 'create', 'target' => 'collection']]]]];
        $this->assertNotNull(ImportDocument::fromArray($withoutCreate)['document']);

        unset($withoutCreate['models'][0]['routes']);
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray($withoutCreate);
        $this->assertNull($document);
        $this->assertContains('/models/0/endpoints/0/uri', array_column($errors, 'path'));
    }

    public function test_una_relacion_puede_llamarse_en_camel_case_como_su_metodo(): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray(['models' => [[
            'name' => 'Practitioner',
            'props' => [['name' => 'name', 'type' => 'string']],
            'load_relations' => [['type' => 'hasMany', 'related' => 'Location', 'name' => 'practitionerLocations', 'namespace' => 'App\\Models']],
            'load_counts' => ['practitionerLocations'],
        ]]]);

        $this->assertNotNull($document, json_encode($errors) ?: '');
    }

    public function test_lo_nuevo_bien_declarado_se_acepta_y_no_cambia_lo_que_no_se_declara(): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray(['models' => [[
            'name' => 'Product',
            'table' => 'catalog_products',
            'props' => [
                ['name' => 'sku', 'type' => 'string', 'length' => 40],
                ['name' => 'price', 'type' => 'decimal', 'precision' => 16, 'scale' => 4],
                ['name' => 'seen_at', 'type' => 'dateTime', 'use_current' => true],
                ['name' => 'plain', 'type' => 'string'],
            ],
            'indexes' => [['columns' => ['sku'], 'unique' => true], ['columns' => ['deleted_at', 'id']]],
            'endpoints' => [['name' => 'publish', 'method' => 'post', 'uri' => 'publish_now/{product_id}', 'route_name' => 'publish_now']],
        ]]]);

        $this->assertNotNull($document, json_encode($errors) ?: '');
        $model = $document->model('Product');

        $this->assertSame('publish_now', $model['endpoints'][0]['route']);
        $this->assertArrayNotHasKey('length', $model['props'][3], 'Lo que no se declara no se rellena: el manifiesto sale como antes.');
        $this->assertArrayNotHasKey('use_current', $model['props'][3]);
        $this->assertSame('import.roster', Endpoints::declaredRouteName(['name' => 'importRoster']));
        $this->assertSame(['campaignId', 'x_y'], Endpoints::parameters('{campaignId}/a/{x_y?}'));
    }
}
