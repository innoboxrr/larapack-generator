<?php

namespace Innoboxrr\LarapackGenerator\Tools\Controller;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ControllerTool extends Tool implements ModelArtifact
{
    protected string $controllerPath = '';

    protected string $controllerTemplatePath = '';

    private function setControllerPath(): static
    {
        $this->controllerPath = get_path(app_dir_name().'/Http/Controllers');

        return $this;
    }

    private function setControllerTemplatePath(): static
    {
        $this->controllerTemplatePath = stubs_path('Controller');

        return $this;
    }

    private function checkControllerClass(): void
    {
        if (app_dir_name() == 'src') {
            $controllerFile = $this->controllerPath.'/'.'Controller.php';

            $this->generate($this->controllerTemplatePath.'/Controller.txt', $controllerFile);
        }
    }

    private function setUp(string $ModelName): void
    {
        $this->init($ModelName)
            ->setControllerPath()
            ->setControllerTemplatePath()
            ->checkControllerClass();
    }

    public function create(string $ModelName): bool
    {
        if (! $this->init($ModelName)->declaresAnyAction()) {
            return false;
        }

        $this->setUp($ModelName);
        $controllerFile = $this->controllerPath.'/'.$this->PascalCaseModelName.'Controller.php';

        return $this->generate($this->controllerTemplatePath.'/ControllerTemplate.txt', $controllerFile);
    }

    /**
     * Un endpoint en el controlador es lo mismo que una acción CRUD: el método
     * recibe su request y devuelve `$request->handle()`, nada más. Es la forma
     * que los agentes respetaban en lo generado y perdían en lo escrito a mano.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        $lines = [];

        foreach ($this->endpoints() as $endpoint) {
            if ($marker === 'ENDPOINT_REQUESTS') {
                $lines[] = $endpoint['request'].',';
            }

            if ($marker === 'ENDPOINT_ACTIONS') {
                array_push(
                    $lines,
                    '',
                    "public function {$endpoint['name']}({$endpoint['request']} \$request)",
                    '{',
                    '    return $request->handle();',
                    '}'
                );
            }
        }

        return $lines;
    }

    /**
     * Un endpoint público sale de `auth:sanctum` por su nombre, y sólo él. La
     * autorización no desaparece: la request sigue preguntando a la política.
     */
    protected function applyEndpoints(string $file): void
    {
        parent::applyEndpoints($file);

        $public = array_column(array_filter($this->endpoints(), fn (array $endpoint): bool => $endpoint['public']), 'name');

        if ($public === []) {
            return;
        }

        $except = implode(', ', array_map(fn (string $name): string => "'{$name}'", $public));

        Files::write($file, str_replace(
            "new Middleware('auth:sanctum'),",
            "new Middleware('auth:sanctum', except: [{$except}]),",
            self::contents($file)
        ));
    }

    public function remove(string $ModelName): bool
    {
        $this->setUp($ModelName);
        $path = $this->controllerPath.'/'.$this->PascalCaseModelName.'Controller.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;
    }
}
