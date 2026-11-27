<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class AuthServiceProviderTool extends Tool
{
    protected string $authServiceProviderPath = '';

    protected string $authServiceProviderTemplatePath = '';

    private function setAuthServiceProviderPath(): static
    {

        $this->authServiceProviderPath = get_path(app_dir_name().'/Providers');

        return $this;

    }

    private function setAuthServiceProviderTemplatePath(): static
    {

        $this->authServiceProviderTemplatePath = stubs_path('Providers');

        return $this;

    }

    public function create(): bool
    {

        $this->init('')
            ->setAuthServiceProviderPath()
            ->setAuthServiceProviderTemplatePath()
            ->addProvidersToComposerJson([$this->namespace.'Providers\AuthServiceProvider']);

        $authServiceProviderFile = $this->authServiceProviderPath.'/AuthServiceProvider.php';

        return $this->generate($this->authServiceProviderTemplatePath.'/AuthServiceProviderTemplate.txt', $authServiceProviderFile);

    }
}
