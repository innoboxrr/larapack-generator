<?php

namespace Innoboxrr\LarapackGenerator\Tools\Resource;

use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Support\Translations;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ResourceTool extends Tool
{
    protected $resourcePath;

    protected $resourceTemplatePath;

    private function setResourcePath()
    {

        $this->resourcePath = get_path(app_dir_name().'/Http/Resources/Models');

        return $this;

    }

    private function setResourceTemplatePath()
    {

        $this->resourceTemplatePath = stubs_path('Resource');

        return $this;

    }

    public function create(string $ModelName)
    {

        $this->init($ModelName)
            ->setResourcePath()
            ->setResourceTemplatePath();

        $resourceFile = $this->resourcePath.'/'.$this->PascalCaseModelName.'Resource.php';

        $created = $this->generate($this->resourceTemplatePath.'/ResourceTemplate.txt', $resourceFile);

        // Los nombres de las acciones que la tabla enseña en cada fila.
        Translations::sync(root_path().'/lang', [$resourceFile], Translations::BACKEND, ['es']);

        return $created;

    }

    /**
     * Los endpoints que la interfaz ofrece sobre un registro, en el menú de
     * cada fila de la tabla. La tabla sólo recibe acciones por fila de aquí,
     * así que aquí van, con la misma forma que `delete`: el `id` es el nombre
     * del endpoint —la clave con la que responde `policies`— y `callback`, la
     * función del contrato JS que lo llama y pregunta antes.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        if ($marker !== 'ENDPOINT_ROW_ACTIONS') {
            return [];
        }

        $lines = [];

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint['target'] !== 'record' || ! Endpoints::offersInterface($endpoint)) {
                continue;
            }

            $danger = $endpoint['method'] === 'delete';

            $lines = [
                ...$lines,
                '$actions[] = [',
                "    'id' => ".self::phpString($endpoint['name']).',',
                "    'name' => __(".self::phpString(Endpoints::label($endpoint['name'])).'),',
                "    'success' => __('The action was completed.'),",
                "    'callback' => ".self::phpString(Endpoints::jsFunction($endpoint)).',',
                "    'icon' => ".($danger ? "'delete'" : "'play'").',',
                ...($danger ? ["    'danger' => true,"] : []),
                "    'route' => false,",
                "    'policy' => false,",
                "    'params' => [",
                "        'id' => \$this->id,",
                '    ],',
                '];',
                '',
            ];
        }

        return $lines;
    }

    public function remove(string $ModelName)
    {

        $this->init($ModelName)
            ->setResourcePath();

        $path = $this->resourcePath.'/'.$this->PascalCaseModelName.'Resource.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
