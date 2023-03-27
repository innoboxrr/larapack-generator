<?php

namespace Innoboxrr\LarapackGenerator\Tools\Config;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

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

		if(!file_exists($configFile)) {

			$templateFile = $this->configTemplatePath . '/ConfigTemplate.txt';

			if(copy($templateFile, $configFile)) {

				$this->replaceData($configFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

	public function remove()
	{
		
		$this->setUp();

		$path = $this->configPath . '/' . $this->namespaceWithoutSeparation . '.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}