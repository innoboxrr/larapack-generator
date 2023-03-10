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

	public function create()
	{

		$this->init('')
			->setConfigPath()
			->setConfigTemplatePath();

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

}