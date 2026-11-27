<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class RouteServiceProviderTool extends Tool
{
    protected string $routeServiceProviderPath = '';

    protected string $routeServiceProviderTemplatePath = '';

    private function setRouteServiceProviderPath(): static
    {

        $this->routeServiceProviderPath = get_path(app_dir_name().'/Providers');

        return $this;

    }

    private function setRouteServiceProviderTemplatePath(): static
    {

        $this->routeServiceProviderTemplatePath = stubs_path('Providers');

        return $this;

    }

    public function create(): bool
    {

        $this->init('')
            ->setRouteServiceProviderPath()
            ->setRouteServiceProviderTemplatePath()
            ->addProvidersToComposerJson([$this->namespace.'Providers\RouteServiceProvider']);

        $routeServiceProviderFile = $this->routeServiceProviderPath.'/RouteServiceProvider.php';

        return $this->generate($this->routeServiceProviderTemplatePath.'/RouteServiceProviderTemplate.txt', $routeServiceProviderFile);

    }
}
