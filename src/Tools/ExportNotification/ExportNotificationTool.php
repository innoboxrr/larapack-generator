<?php

namespace Innoboxrr\LarapackGenerator\Tools\ExportNotification;

use Innoboxrr\LarapackGenerator\Support\Translations;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ExportNotificationTool extends Tool implements ModelArtifact
{
    protected string $exportNotificationPath = '';

    protected string $exportNotificationTemplatePath = '';

    protected string $modelExportNotificationPath = '';

    private function setExportNotificationPath(): static
    {

        $this->exportNotificationPath = get_path(app_dir_name().'/Notifications');

        return $this;

    }

    private function setExportNotificationTemplatePath(): static
    {

        $this->exportNotificationTemplatePath = stubs_path('ExportNotification');

        return $this;

    }

    protected function setModelExportNotificationPath(): static
    {

        $path = $this->exportNotificationPath.'/'.$this->PascalCaseModelName;

        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $this->modelExportNotificationPath = $path;

        return $this;

    }

    public function create(string $ModelName): bool
    {

        if (! $this->init($ModelName)->declares('export')) {
            return false;
        }

        $this->init($ModelName)
            ->setExportNotificationPath()
            ->setExportNotificationTemplatePath()
            ->setModelExportNotificationPath();

        $exportNotificationFile = $this->modelExportNotificationPath.'/'.'ExportNotification.php';

        $created = $this->generate($this->exportNotificationTemplatePath.'/ExportNotificationTemplate.txt', $exportNotificationFile);

        Translations::sync(root_path().'/lang', [$exportNotificationFile], Translations::BACKEND, ['es']);

        return $created;

    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setExportNotificationPath();

        $path = $this->exportNotificationPath.'/'.$this->PascalCaseModelName;

        return $this->dropModelDir($path);

    }
}
