<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use PHPUnit\Framework\TestCase;

final class TokenReplacementTest extends TestCase
{
    private FakeProject $project;

    private ExposedTool $tool;

    protected function setUp(): void
    {
        $this->project = FakeProject::library('Acme\\Shop\\');

        ProjectRoot::set($this->project->path);

        $this->tool = new ExposedTool();
    }

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        $this->project->cleanup();
    }

    public function test_sustituye_cada_token_por_su_variante(): void
    {
        $this->tool->prepare('OrderItem');

        $replaced = $this->tool->replace(implode("\n", [
            'pluralModelName',
            'plural_snake_case_model_name',
            'pluralCamelCaseModelName',
            'PluralPascalCaseModelName',
            'pluralkebabcasemodelname',
            'pluralDotModelName',
            'snake_case_model_name',
            'camelCaseModelName',
            'PascalCaseModelName',
            'kebabcasemodelname',
            'dotModelName',
            'ModelName',
        ]));

        $this->assertSame(implode("\n", [
            'OrderItems',
            'order_items',
            'orderItems',
            'OrderItems',
            'order-items',
            'order.items',
            'order_item',
            'orderItem',
            'OrderItem',
            'order-item',
            'order.item',
            'OrderItem',
        ]), $replaced);
    }

    public function test_los_tokens_de_namespace_salen_del_composer_json_del_destino(): void
    {
        $this->tool->prepare('Order');

        $this->assertSame('Acme\Shop\Models', $this->tool->replace('Namespace\Models'));
        $this->assertSame('acme.shop.', $this->tool->replace('dotNamespace'));
        $this->assertSame('acme-shop-', $this->tool->replace('kebabNamespace'));
        $this->assertSame('acmeshop', $this->tool->replace('namespaceWithoutSeparation'));
        $this->assertSame('acme/shop/', $this->tool->replace('slashLowerNamespace'));
    }

    /**
     * El motivo de usar strtr() en lugar de encadenar str_replace(): un valor
     * ya insertado no se vuelve a recorrer, asi que no puede coincidir con
     * otro token y ser sustituido por segunda vez.
     */
    public function test_no_re_sustituye_lo_que_ya_ha_insertado(): void
    {
        // El nombre del modelo contiene literalmente otro de los tokens.
        $this->tool->prepare('ModelName');

        $this->assertSame('ModelName', $this->tool->replace('PascalCaseModelName'));
        $this->assertSame('model_name', $this->tool->replace('snake_case_model_name'));
    }

    /**
     * Los plurales tienen que ganar a los singulares. strtr() lo garantiza
     * por longitud de clave, sin depender del orden del array.
     */
    public function test_los_tokens_plurales_ganan_a_los_singulares(): void
    {
        $this->tool->prepare('Category');

        $this->assertSame('categories', $this->tool->replace('plural_snake_case_model_name'));
        $this->assertSame('Categories', $this->tool->replace('PluralPascalCaseModelName'));
    }
}

/**
 * Tool tiene init() y replaceTokens() protegidos porque son detalle interno
 * de las herramientas; aqui se exponen para poder probarlos aislados.
 */
final class ExposedTool extends Tool
{
    public function prepare(string $modelName): void
    {
        $this->init($modelName);
    }

    public function replace(string $content): string
    {
        return $this->replaceTokens($content);
    }
}
