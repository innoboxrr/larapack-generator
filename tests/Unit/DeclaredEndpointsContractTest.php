<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `endpoints` y `controllers` (SPEC 02): lo que el contrato acepta, lo que
 * rechaza y lo que deja una vez resuelto.
 *
 * Todo lo que se rechaza aquí acabaría, si no, en un archivo que no compila,
 * en una ruta tapada por otra o en una política que pregunta por un método que
 * no existe. Mejor en validate que en producción.
 */
final class DeclaredEndpointsContractTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @param  array<string, mixed>  $overrides
     * @param  array<int, array<string, mixed>>  $controllers
     * @return array{document: ImportDocument|null, errors: array<int, array<string, mixed>>}
     */
    private function parse(array $endpoints, array $overrides = [], array $controllers = []): array
    {
        $document = ['models' => [array_merge([
            'name' => 'Course',
            'props' => [['name' => 'title', 'type' => 'string']],
            'endpoints' => $endpoints,
        ], $overrides)]];

        if ($controllers !== []) {
            $document['controllers'] = $controllers;
        }

        return ImportDocument::fromArray($document);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, string>
     */
    private function messages(array $errors, string $level = SemanticValidator::ERROR): array
    {
        return array_map(
            fn (array $e): string => $e['path'].' '.$e['message'],
            array_values(array_filter($errors, fn (array $e): bool => $e['level'] === $level))
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function assertRejected(array $errors, string $path, string $fragment): void
    {
        foreach ($this->messages($errors) as $message) {
            if (str_starts_with($message, $path) && str_contains($message, $fragment)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("No hay un error en {$path} que diga «{$fragment}». Errores:\n".implode("\n", $this->messages($errors)));
    }

    // LO QUE QUEDA RESUELTO

    public function test_sin_endpoints_ni_controllers_el_documento_es_el_de_siempre(): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray(['models' => [
            ['name' => 'Course', 'props' => [['name' => 'title', 'type' => 'string']]],
        ]]);

        $this->assertSame([], $errors);
        $this->assertSame([], $document->model('Course')['endpoints']);
        $this->assertSame([], $document->controllers());
    }

    public function test_resuelve_uri_ruta_habilidad_y_operacion_por_omision(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([
            ['name' => 'importRoster', 'method' => 'post'],
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([
            'name' => 'importRoster',
            'method' => 'post',
            'uri' => 'import-roster',
            'route' => 'import.roster',
            'request' => 'ImportRosterRequest',
            'target' => 'record',
            'ability' => 'importRoster',
            'operation' => 'importRosterModel',
            'public' => false,
            'middleware' => [],
        ], $document->model('Course')['endpoints'][0]);
    }

    public function test_un_controlador_no_tiene_registro_ni_operacion_y_su_habilidad_es_opcional(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([], controllers: [
            ['name' => 'Desk', 'endpoints' => [
                ['name' => 'overview', 'method' => 'get'],
                ['name' => 'revoke', 'method' => 'post', 'ability' => 'desk.revoke'],
            ]],
        ]);

        $this->assertSame([], $errors);

        [$overview, $revoke] = $document->controllers()[0]['endpoints'];

        $this->assertNull($overview['target']);
        $this->assertNull($overview['ability']);
        $this->assertNull($overview['operation']);
        $this->assertSame('desk.revoke', $revoke['ability']);
    }

    // ESQUEMA

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformed(): array
    {
        return [
            'nombre en PascalCase' => [['name' => 'Publish', 'method' => 'post'], '/models/0/endpoints/0/name'],
            'nombre en snake_case' => [['name' => 'send_reminder', 'method' => 'post'], '/models/0/endpoints/0/name'],
            'método fuera del enum' => [['name' => 'publish', 'method' => 'head'], '/models/0/endpoints/0/method'],
            'uri que sube de directorio' => [['name' => 'publish', 'method' => 'post', 'uri' => '../admin'], '/models/0/endpoints/0/uri'],
            'uri con .. en medio' => [['name' => 'publish', 'method' => 'post', 'uri' => 'a/../b'], '/models/0/endpoints/0/uri'],
            'uri con barra inicial' => [['name' => 'publish', 'method' => 'post', 'uri' => '/publish'], '/models/0/endpoints/0/uri'],
            'target desconocido' => [['name' => 'publish', 'method' => 'post', 'target' => 'many'], '/models/0/endpoints/0/target'],
            'middleware con espacios' => [['name' => 'publish', 'method' => 'post', 'middleware' => ['auth; rm']], '/models/0/endpoints/0/middleware/0'],
            'clave desconocida' => [['name' => 'publish', 'method' => 'post', 'controller' => 'X'], '/models/0/endpoints/0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    #[DataProvider('malformed')]
    public function test_el_esquema_rechaza(array $endpoint, string $path): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([$endpoint]);

        $this->assertNull($document);
        $this->assertContains($path, array_column($errors, 'path'), implode("\n", $this->messages($errors)));
    }

    // SEMÁNTICA

    public function test_un_nombre_crud_se_declara_con_routes(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([['name' => 'forceDelete', 'method' => 'delete']]);

        $this->assertNull($document);
        $this->assertRejected($errors, '/models/0/endpoints/0/name', 'acción CRUD');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reserved(): array
    {
        return [
            'el middleware del controlador' => ['middleware'],
            'el authorize de AuthorizesRequests' => ['authorize'],
            'el validate de ValidatesRequests' => ['validate'],
            'FormRequest extendiéndose a sí misma' => ['form'],
            'una palabra reservada' => ['list'],
            'otra palabra reservada' => ['new'],
        ];
    }

    #[DataProvider('reserved')]
    public function test_un_nombre_que_choca_con_php_o_laravel(string $name): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([['name' => $name, 'method' => 'post']]);

        $this->assertNull($document);
        $this->assertRejected($errors, '/models/0/endpoints/0/name', 'choca');
        $this->assertCount(1, $this->messages($errors), 'Un nombre inválido no debería repetirse como habilidad inválida.');
    }

    public function test_nombres_y_rutas_repetidas(): void
    {
        ['errors' => $errors] = $this->parse([
            ['name' => 'publish', 'method' => 'post'],
            ['name' => 'publish', 'method' => 'put'],
            ['name' => 'release', 'method' => 'post', 'uri' => 'publish'],
        ]);

        $this->assertRejected($errors, '/models/0/endpoints/1/name', 'dos veces');
        $this->assertRejected($errors, '/models/0/endpoints/2/uri', 'POST publish');
    }

    public function test_una_uri_crud_taparia_el_endpoint(): void
    {
        ['errors' => $errors] = $this->parse([['name' => 'purge', 'method' => 'delete', 'uri' => 'force-delete']]);

        $this->assertRejected($errors, '/models/0/endpoints/0/uri', 'acción CRUD');
    }

    public function test_el_registro_en_la_uri_se_llama_como_lo_lee_la_request(): void
    {
        ['errors' => $errors] = $this->parse([['name' => 'publish', 'method' => 'post', 'uri' => '{course}/publish']]);

        $this->assertRejected($errors, '/models/0/endpoints/0/uri', '{course_id}');

        ['errors' => $errors] = $this->parse([['name' => 'publish', 'method' => 'post', 'uri' => '{course_id}/publish']]);

        $this->assertSame([], $this->messages($errors));
    }

    public function test_una_habilidad_crud_que_el_modelo_no_tiene(): void
    {
        ['errors' => $errors] = $this->parse(
            [['name' => 'archive', 'method' => 'post', 'ability' => 'update']],
            ['routes' => ['only' => ['index', 'show']]]
        );

        $this->assertRejected($errors, '/models/0/endpoints/0/ability', 'no tiene update');
    }

    public function test_una_habilidad_crud_con_otra_firma(): void
    {
        ['errors' => $errors] = $this->parse([['name' => 'importRoster', 'method' => 'post', 'target' => 'collection', 'ability' => 'update']]);

        $this->assertRejected($errors, '/models/0/endpoints/0/ability', 'recibe un registro');
    }

    public function test_un_endpoint_publico_no_puede_usar_una_habilidad_crud(): void
    {
        ['errors' => $errors] = $this->parse([['name' => 'preview', 'method' => 'get', 'ability' => 'view', 'public' => true, 'middleware' => ['throttle:30,1']]]);

        $this->assertRejected($errors, '/models/0/endpoints/0/ability', 'invitados');
    }

    public function test_una_habilidad_compartida_necesita_la_misma_firma(): void
    {
        ['errors' => $errors] = $this->parse([
            ['name' => 'publish', 'method' => 'post', 'ability' => 'manage'],
            ['name' => 'importRoster', 'method' => 'post', 'ability' => 'manage', 'target' => 'collection'],
        ]);

        $this->assertRejected($errors, '/models/0/endpoints/1/ability', 'dos firmas');
    }

    public function test_before_no_puede_ser_una_habilidad(): void
    {
        ['errors' => $errors] = $this->parse([['name' => 'publish', 'method' => 'post', 'ability' => 'before']]);

        $this->assertRejected($errors, '/models/0/endpoints/0/ability', 'before()');
    }

    public function test_un_endpoint_publico_sin_throttle_avisa(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([['name' => 'certificate', 'method' => 'get', 'public' => true]]);

        $this->assertNotNull($document);

        $warnings = $this->messages($errors, SemanticValidator::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('throttle', $warnings[0]);
    }

    public function test_un_controlador_que_se_llama_como_un_modelo_o_con_sufijo(): void
    {
        ['errors' => $errors] = $this->parse([], controllers: [
            ['name' => 'Course', 'endpoints' => [['name' => 'overview', 'method' => 'get']]],
            ['name' => 'DeskController', 'endpoints' => [['name' => 'overview', 'method' => 'get']]],
            ['name' => 'Wizard', 'endpoints' => [['name' => 'step', 'method' => 'post']]],
            ['name' => 'Wizard', 'endpoints' => [['name' => 'finish', 'method' => 'post']]],
        ]);

        $this->assertRejected($errors, '/controllers/0/name', 'como un modelo');
        $this->assertRejected($errors, '/controllers/1/name', "Declara 'Desk'");
        $this->assertRejected($errors, '/controllers/3/name', 'dos veces');
    }

    public function test_un_controlador_sin_endpoints_no_tiene_sentido(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([], controllers: [['name' => 'Desk', 'endpoints' => []]]);

        $this->assertNull($document);
        $this->assertContains('/controllers/0/endpoints', array_column($errors, 'path'));
    }
}
