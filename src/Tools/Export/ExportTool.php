<?php

namespace Innoboxrr\LarapackGenerator\Tools\Export;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class ExportTool extends Tool
{
    protected string $exportPath = '';

    protected string $exportTemplatePath = '';

    private function setExportPath(): static
    {

        $this->exportPath = get_path(app_dir_name().'/Exports');

        return $this;

    }

    private function setExportTemplatePath(): static
    {

        $this->exportTemplatePath = stubs_path('Export');

        return $this;

    }

    private function setUp(string $ModelName): void
    {

        $this->init($ModelName)
            ->setExportPath()
            ->setExportTemplatePath();

    }

    public function create(string $ModelName): bool
    {

        if (! $this->init($ModelName)->declares('export')) {
            return false;
        }

        $this->setUp($ModelName);

        $exportFile = $this->exportPath.'/'.$this->PluralPascalCaseModelName.'Exports.php';

        return $this->generate($this->exportTemplatePath.'/ExportTemplate.txt', $exportFile);

    }

    public function remove(string $ModelName): bool
    {

        $this->setUp($ModelName);

        $path = $this->exportPath.'/'.$this->PluralPascalCaseModelName.'Exports.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
