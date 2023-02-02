<?php

namespace Desar\Generator\Tools\Makers\Model;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class ModelGenerator extends MakerTool
{

	protected $modelPath;

	protected $modelTemplatePath;

	private function setModelPath()
	{

		$this->modelPath = get_path(app_dir_name() . '/Models');

		rfeturn $this;

	}

	private function setModelTemplatePath()
	{

		$this->modelTemplatePath = get_path(app_dir_name() . '/Stubs/Model');

		rfeturn $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setModelPath()
			->setModelTemplatePath();

		$modelFile = $this->modelPath . '/' . $this->PascalCaseModelName . '.php';

		if(!file_exists($modelFile)) {

			$templateFile = $this->modelTemplatePath . '/ModelTemplate.txt';

			if(copy($templateFile, $modelFile)) {

				$this->replaceData($modelFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}