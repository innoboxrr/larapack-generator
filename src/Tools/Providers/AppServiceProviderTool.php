<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\PackageArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class AppServiceProviderTool extends Tool implements PackageArtifact
{
    protected string $appServiceProviderPath = '';

    protected string $appServiceProviderTemplatePath = '';

    private function setAppServiceProviderPath(): static
    {

        $this->appServiceProviderPath = get_path(app_dir_name().'/Providers');

        return $this;

    }

    private function setAppServiceProviderTemplatePath(): static
    {

        $this->appServiceProviderTemplatePath = stubs_path('Providers');

        return $this;

    }

    public function create(): bool
    {

        $this->init('')
            ->setAppServiceProviderPath()
            ->setAppServiceProviderTemplatePath()
            ->addProvidersToComposerJson([$this->namespace.'Providers\AppServiceProvider']);

        $appServiceProviderFile = $this->appServiceProviderPath.'/AppServiceProvider.php';

        return $this->generate($this->appServiceProviderTemplatePath.'/AppServiceProviderTemplate.txt', $appServiceProviderFile);

    }
}
