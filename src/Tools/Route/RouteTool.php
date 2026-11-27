<?php

namespace Innoboxrr\LarapackGenerator\Tools\Route;

use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class RouteTool extends Tool
{
    protected string $apiRoutepath = '';

    protected string $routeTemplatePath = '';

    private function setApiRoutepath(): static
    {

        $this->apiRoutepath = get_path('routes/api/models');

        return $this;

    }

    private function setRouteTemplatePath(): static
    {

        $this->routeTemplatePath = stubs_path('Route');

        return $this;

    }

    public function create(string $ModelName): bool
    {

        if (! $this->init($ModelName)->declaresAnyAction()) {
            return false;
        }

        $this->init($ModelName)
            ->setApiRoutepath()
            ->setRouteTemplatePath();

        $routeFile = $this->apiRoutepath.'/'.$this->snake_case_model_name.'.php';

        return $this->generate($this->routeTemplatePath.'/RouteTemplate.txt', $routeFile);

    }

    /**
     * Las rutas de los endpoints van detrás de las CRUD y con la misma forma:
     * se llaman por nombre desde el front, así que el nombre se deriva igual
     * (`importRoster` → `import.roster`, como `forceDelete` → `force.delete`).
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        if ($marker !== 'ENDPOINT_ROUTES') {
            return [];
        }

        $controller = $this->PascalCaseModelName.'Controller';
        $id = $this->snake_case_model_name.'_id';
        $lines = [];

        foreach ($this->endpoints() as $endpoint) {
            $chain = ["->name('{$endpoint['route']}')"];

            // El identificador del registro en la URI: la request no lo valida
            // (rules() no ve los parámetros de ruta), así que lo exige la ruta.
            if ($endpoint['target'] === 'record' && in_array($id, Endpoints::parameters($endpoint['uri']), true)) {
                $chain[] = "->whereNumber('{$id}')";
            }

            if ($endpoint['middleware'] !== []) {
                $chain[] = '->middleware(['.implode(', ', array_map(fn (string $entry): string => var_export($entry, true), $endpoint['middleware'])).'])';
            }

            $lines[] = '';
            $lines[] = "Route::{$endpoint['method']}('{$endpoint['uri']}', [{$controller}::class, '{$endpoint['name']}'])";

            foreach ($chain as $position => $call) {
                $lines[] = "\t".$call.($position === array_key_last($chain) ? ';' : '');
            }
        }

        return $lines;
    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setApiRoutepath();

        $path = $this->apiRoutepath.'/'.$this->snake_case_model_name.'.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
