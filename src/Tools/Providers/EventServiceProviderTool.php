<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class EventServiceProviderTool extends Tool
{
    protected string $eventServiceProviderPath = '';

    protected string $eventServiceProviderTemplatePath = '';

    private function setEventServiceProviderPath(): static
    {

        $this->eventServiceProviderPath = get_path(app_dir_name().'/Providers');

        return $this;

    }

    private function setEventServiceProviderTemplatePath(): static
    {

        $this->eventServiceProviderTemplatePath = stubs_path('Providers');

        return $this;

    }

    public function create(): bool
    {

        $this->init('')
            ->setEventServiceProviderPath()
            ->setEventServiceProviderTemplatePath()
            ->addProvidersToComposerJson([$this->namespace.'Providers\EventServiceProvider']);

        $eventServiceProviderFile = $this->eventServiceProviderPath.'/EventServiceProvider.php';

        return $this->generate($this->eventServiceProviderTemplatePath.'/EventServiceProviderTemplate.txt', $eventServiceProviderFile);

    }
}
