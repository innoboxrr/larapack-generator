<?php

namespace Innoboxrr\LarapackGenerator\Tools\Config;

use Innoboxrr\LarapackGenerator\Tools\PackageArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ConfigTool extends Tool implements PackageArtifact
{
    protected string $configPath = '';

    protected string $configTemplatePath = '';

    private function setConfigPath(): static
    {

        $this->configPath = get_path('config');

        return $this;

    }

    private function setConfigTemplatePath(): static
    {

        $this->configTemplatePath = stubs_path('Config');

        return $this;

    }

    private function setUp(): void
    {

        $this->init('')
            ->setConfigPath()
            ->setConfigTemplatePath();

    }

    public function create(): bool
    {

        $this->setUp();

        $configFile = $this->configPath.'/'.$this->configKey.'.php';

        return $this->generate($this->configTemplatePath.'/ConfigTemplate.txt', $configFile);

    }

    public function remove(): bool
    {

        $this->setUp();

        $path = $this->configPath.'/'.$this->configKey.'.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}
