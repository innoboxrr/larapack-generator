<?php

namespace Innoboxrr\LarapackGenerator\Tools\Endpoint;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Support\StubBlocks;
use Innoboxrr\LarapackGenerator\Tools\Tool;

/**
 * La request y el test de cada endpoint declarado, de un modelo o de un
 * `controllers[]`.
 *
 * El método del controlador, la ruta, la habilidad de la política y la
 * operación del modelo los ponen sus propias herramientas en sus marcadores;
 * aquí va lo que es un archivo por endpoint.
 *
 * Pasa por generate() como cualquier otro archivo: una request que alguien ya
 * rellenó no se pisa al regenerar, y el manifiesto la conoce, así que verify
 * sabe si falta.
 */
class EndpointTool extends Tool
{
    /**
     * El endpoint que se está generando. Los tokens y las condiciones de los
     * stubs se leen de aquí.
     *
     * @var array<string, mixed>|null
     */
    private ?array $endpoint = null;

    public function create(string $ModelName)
    {
        $this->init($ModelName);

        foreach ($this->endpoints() as $endpoint) {
            $this->endpoint = $endpoint;

            $this->generate(
                stubs_path('Endpoint').'/RequestTemplate.txt',
                root_path().'/'.app_dir_name().'/Http/Requests/'.$this->PascalCaseModelName.'/'.$endpoint['request'].'.php'
            );

            $this->generate(
                stubs_path('Endpoint').(app_dir_name() == 'src' ? '/TestPackageTemplate.txt' : '/TestProjectTemplate.txt'),
                root_path().'/tests/Feature/Endpoints/'.$this->PascalCaseModelName.'/'.ucfirst($endpoint['name']).'Test.php'
            );
        }

        $this->endpoint = null;

        return true;
    }

    /**
     * Las requests las retira RequestsTool con su carpeta; aquí, los tests.
     */
    public function remove(string $ModelName)
    {
        $this->init($ModelName);

        $path = root_path().'/tests/Feature/Endpoints/'.$this->PascalCaseModelName;

        return $this->dropModelDir($path);
    }

    /**
     * Las condiciones del endpoint primero; el resto, las del modelo.
     */
    protected function applyBlocks(string $file): void
    {
        $content = file_get_contents($file);

        if (! str_contains($content, '@larapack:')) {
            return;
        }

        file_put_contents($file, StubBlocks::apply(
            $content,
            fn (string $condition): bool => in_array($condition, Endpoints::FLAGS, true)
                ? $this->holds($condition)
                : Declaration::holds($this->ModelName, $condition)
        ));
    }

    private function holds(string $condition): bool
    {
        $endpoint = $this->endpoint ?? [];
        $model = ! Declaration::of($this->ModelName)['controller'];

        return match ($condition) {
            'endpoint_model' => $model,
            'endpoint_record' => $model && ($endpoint['target'] ?? null) === 'record',
            // Si el identificador va en la URI, la ruta lo exige numérico y
            // rules() no lo valida: las reglas miran el cuerpo y la query, no
            // los parámetros de la ruta, y lo rechazarían siempre.
            'endpoint_id_in_body' => $this->holds('endpoint_record')
                && ! in_array($this->snake_case_model_name.'_id', Endpoints::parameters((string) ($endpoint['uri'] ?? '')), true),
            'endpoint_public' => (bool) ($endpoint['public'] ?? false),
            'endpoint_gate' => ! $model && ($endpoint['ability'] ?? null) !== null,
            // Basta la sesión: authorize() no pregunta a ningún Gate, y su
            // test no espera 403 con la autorización negando.
            'endpoint_authenticated' => ! $model && ($endpoint['authorize'] ?? null) === 'authenticated',
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function replacementMap(): array
    {
        $map = parent::replacementMap();

        if ($this->endpoint === null) {
            return $map;
        }

        $endpoint = $this->endpoint;

        return $map + [
            'EndpointClassName' => ucfirst($endpoint['name']),
            'endpointMethodName' => $endpoint['name'],
            'endpointOperationName' => (string) ($endpoint['operation'] ?? ''),
            'endpointAbilityName' => (string) ($endpoint['ability'] ?? ''),
            'endpointRouteName' => $endpoint['route'],
            'endpointHttpVerb' => strtoupper($endpoint['method']),
            'endpointUri' => $endpoint['uri'],
            'endpointGuestRouteArgs' => $this->routeArguments(false),
            'endpointRecordRouteArgs' => $this->routeArguments(true),
            'endpointRecordPayload' => $this->recordPayload(),
        ];
    }

    /**
     * Los parámetros que la URI exige para que route() pueda construirla.
     *
     * Al invitado le basta cualquier valor: el middleware de sesión lo para
     * antes de que nadie lo lea. Con registro —el público sin sesión y la
     * prueba con sesión y la autorización negando—, el identificador tiene que
     * ser el de uno que exista, o la request respondería 404 antes de llegar a
     * la política. Sólo lo hay si el endpoint actúa sobre un registro: es lo
     * que crea la prueba.
     */
    private function routeArguments(bool $withRecord): string
    {
        $parameters = Endpoints::parameters($this->endpoint['uri']);

        if ($parameters === []) {
            return '';
        }

        $id = $this->snake_case_model_name.'_id';
        $record = $withRecord && $this->holds('endpoint_record');

        $pairs = array_map(
            fn (string $parameter): string => var_export($parameter, true).' => '.($record && $parameter === $id ? "\${$this->camelCaseModelName}->id" : '1'),
            $parameters
        );

        return ', ['.implode(', ', $pairs).']';
    }

    /**
     * El identificador del registro, si no va ya en la URI.
     */
    private function recordPayload(): string
    {
        $id = $this->snake_case_model_name.'_id';

        if (! $this->holds('endpoint_id_in_body')) {
            return '[]';
        }

        return "['{$id}' => \${$this->camelCaseModelName}->id]";
    }
}
