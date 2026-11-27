<?php

namespace Innoboxrr\LarapackGenerator\Tools\Policy;

use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class PolicyTool extends Tool implements ModelArtifact
{
    protected string $policyPath = '';

    protected string $policyTemplatePath = '';

    private function setPolicyPath(): static
    {

        $this->policyPath = get_path(app_dir_name().'/Policies');

        return $this;

    }

    private function setPolicyTemplatePath(): static
    {

        $this->policyTemplatePath = stubs_path('Policy');

        return $this;

    }

    public function create(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setPolicyPath()
            ->setPolicyTemplatePath();

        $policyFile = $this->policyPath.'/'.$this->PascalCaseModelName.'Policy.php';

        return $this->generate($this->policyTemplatePath.'/PolicyTemplate.txt', $policyFile);

    }

    /**
     * Un método por habilidad propia, negando, como los CRUD. Una habilidad
     * CRUD no genera nada: el endpoint pregunta al método que ya hay.
     *
     * El de un endpoint público recibe `?User`: Laravel sólo llama a un método
     * de política con un invitado si su firma lo admite. Con un invitado
     * tampoco llama a before(), que pide un User, así que before() no decide
     * nada por quien no tiene sesión. Con sesión sí pasa por before(), como
     * en las CRUD: un administrador autenticado entra también en los
     * endpoints públicos, salvo lo que before() excluya.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        if ($marker !== 'ENDPOINT_ABILITIES') {
            return [];
        }

        $lines = [];
        $written = [];

        foreach ($this->endpoints() as $endpoint) {
            $ability = $endpoint['ability'];

            if (Endpoints::isCrudAbility($ability) || isset($written[$ability])) {
                continue;
            }

            $written[$ability] = true;

            $parameters = ($endpoint['public'] ? '?User' : 'User').' $user';

            if ($endpoint['target'] === 'record') {
                $parameters .= ", {$this->PascalCaseModelName} \${$this->camelCaseModelName}";
            }

            array_push(
                $lines,
                '',
                "public function {$ability}({$parameters}): Response|bool",
                '{',
                '    return false;',
                '}'
            );
        }

        return $lines;
    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setPolicyPath();

        $path = $this->policyPath.'/'.$this->PascalCaseModelName.'Policy.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
