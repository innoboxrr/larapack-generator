<?php

namespace Innoboxrr\LarapackGenerator\Tools\Controller;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ControllerTool extends Tool
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
		$this->controllerTemplatePath = stubs_path('Controller');
		return $this;
	}

	private function checkControllerClass()
	{
		if(app_dir_name() == 'src') {
			$controllerFile = $this->controllerPath . '/' . 'Controller.php';
			if(!file_exists($controllerFile)) {
				$templateFile = $this->controllerTemplatePath . '/Controller.txt';
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

	private function setUp(string $ModelName)
	{
		$this->init($ModelName)
			->setControllerPath()
			->setControllerTemplatePath()
			->checkControllerClass();
	}

	public function create(string $ModelName)
	{
		$this->setUp($ModelName);
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

	public function remove(string $ModelName)
	{
		$this->setUp($ModelName);
		$path = $this->controllerPath . '/' . $this->PascalCaseModelName . 'Controller.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}
}