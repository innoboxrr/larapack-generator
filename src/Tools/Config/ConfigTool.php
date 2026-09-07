<?php

namespace Innoboxrr\LarapackGenerator\Tools\Config;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class ConfigTool extends Tool
{

	protected $configPath;

	protected $configTemplatePath;

	private function setConfigPath()
	{

		$this->configPath = get_path('config');

		return $this;

	}

	private function setConfigTemplatePath()
	{

		$this->configTemplatePath = stubs_path('Config');

		return $this;

	}

	private function setUp()
	{

		$this->init('')
			->setConfigPath()
			->setConfigTemplatePath();

	}

	public function create()
	{

		$this->setUp();

		$configFile = $this->configPath . '/' . $this->namespaceWithoutSeparation . '.php';

		return $this->generate($this->configTemplatePath . '/ConfigTemplate.txt', $configFile);

	}

	public function remove()
	{
		
		$this->setUp();

		$path = $this->configPath . '/' . $this->namespaceWithoutSeparation . '.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}