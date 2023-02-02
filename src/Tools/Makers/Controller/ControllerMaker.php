<?php

namespace Desar\Generator\Tools\Makers\Controller;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class ControllerMaker extends MakerTool
{

	protected $controllerPath;

	protected $controllerTemplatePath;

	private function setControllerPath()
	{

		$this->controllerPath = get_path(app_dir_name() . '/Http/Controllers');

		return $this;

	}

	private function setControllerTemplatePath()
		{

			$this->controllerTemplatePath = get_path(app_dir_name() . '/Stubs/Controller');

			return $this;

		}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setControllerPath()
			->setControllerTemplatePath();

		$controllerFile = $this->controllerPath . '/' . $this->PascalCaseModelName . 'Controller.php';

		if(!file_exists($controllerFile)) {

			$templateFile = $this->controllerTemplatePath . '/ControllerTemplate.txt';

			if(copy($templateFile, $controllerFile)) {

				$this->replaceData($controllerFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}