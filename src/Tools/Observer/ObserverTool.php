<?php

namespace Innoboxrr\LarapackGenerator\Tools\Observer;

use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ObserverTool extends Tool implements ModelArtifact
{
    protected string $observerPath = '';

    protected string $observerTemplatePath = '';

    private function setObserverPath(): static
    {

        $this->observerPath = get_path(app_dir_name().'/Observers');

        return $this;

    }

    private function setObserverTemplatePath(): static
    {

        $this->observerTemplatePath = stubs_path('Observer');

        return $this;

    }

    public function create(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setObserverPath()
            ->setObserverTemplatePath();

        $observerFile = $this->observerPath.'/'.$this->PascalCaseModelName.'Observer.php';

        return $this->generate($this->observerTemplatePath.'/ObserverTemplate.txt', $observerFile);

    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setObserverPath();

        $path = $this->observerPath.'/'.$this->PascalCaseModelName.'Observer.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
