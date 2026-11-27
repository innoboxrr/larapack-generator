<?php

namespace Innoboxrr\LarapackGenerator\Tools\Excel;

use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ExcelTool extends Tool implements ModelArtifact
{
    protected string $excelPath = '';

    protected string $excelTemplatePath = '';

    private function setExcelPath(): static
    {

        $this->excelPath = get_path('resources/views/excel');

        return $this;

    }

    private function setExcelTemplatePath(): static
    {

        $this->excelTemplatePath = stubs_path('Excel');

        return $this;

    }

    private function setUp(string $ModelName): void
    {

        $this->init($ModelName)
            ->setExcelPath()
            ->setExcelTemplatePath();

    }

    public function create(string $ModelName): bool
    {

        if (! $this->init($ModelName)->declares('export')) {
            return false;
        }

        $this->setUp($ModelName);

        $excelFile = $this->excelPath.'/'.$this->snake_case_model_name.'.blade.php';

        return $this->generate($this->excelTemplatePath.'/ExcelTemplate.txt', $excelFile);

    }

    public function remove(string $ModelName): bool
    {

        $this->setUp($ModelName);

        $path = $this->excelPath.'/'.$this->snake_case_model_name.'.blade.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
