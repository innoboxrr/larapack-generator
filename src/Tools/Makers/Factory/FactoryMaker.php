<?php

namespace Desar\Generator\Tools\Makers\Factory;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class FactoryGenerator extends MakerTool
{

	protected $factoryPath;

	protected $factoryTemplatePath;

	private function setFactoryPath()
	{

		$this->factoryPath = get_path('database/factories');

		return $this;

	}

	private function setFactoryTemplatePath()
	{

		$this->factoryTemplatePath = get_path(app_dir_name() . '/Stubs/Factory');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setFactoryPath()
			->setFactoryTemplatePath();

		$factoryFile = $this->factoryPath . '/' . $this->PascalCaseModelName . 'Factory.php';

		if(!file_exists($factoryFile)) {

			$templateFile = $this->factoryTemplatePath . '/FactoryTemplate.txt';

			if(copy($templateFile, $factoryFile)) {

				$this->replaceData($factoryFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}