<?php

namespace Innoboxrr\LarapackGenerator\Tools\Filters;

use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class FiltersTool extends Tool implements ModelArtifact
{
    protected string $filtersPath = '';

    protected string $filtersTemplatePath = '';

    protected string $mainFiltersPath = '';

    /** @var list<string> */
    protected array $filters = [
        'CreationFilter',
        'EagerLoadingFilter',
        'IdFilter',
        'ManagedFilter',
        'UpdatedFilter',
    ];

    private function setFiltersPath(): static
    {

        $this->filtersPath = get_path(app_dir_name().'/Models/Filters');

        return $this;

    }

    private function setFiltersTemplatePath(): static
    {

        $this->filtersTemplatePath = stubs_path('Filters');

        return $this;

    }

    protected function setMainFiltersPath(): static
    {

        $path = $this->filtersPath.'/'.$this->PascalCaseModelName;

        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $this->mainFiltersPath = $path;

        return $this;

    }

    public function create(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setFiltersPath()
            ->setFiltersTemplatePath()
            ->setMainFiltersPath();

        foreach ($this->filters as $filter) {

            $this->createRequestFile($filter);

        }

        return true;

    }

    protected function createRequestFile(string $filterName): bool
    {

        $filterFile = $this->mainFiltersPath.'/'.$filterName.'.php';

        return $this->generate($this->filtersTemplatePath.'/'.$filterName.'Template.txt', $filterFile);

    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setFiltersPath();

        $path = $this->filtersPath.'/'.$this->PascalCaseModelName;

        return $this->dropModelDir($path);

    }
}
