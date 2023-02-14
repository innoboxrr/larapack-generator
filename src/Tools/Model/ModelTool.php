<?php

namespace Desar\Generator\Tools\Model;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class ModelTool extends Tool
{

	protected $modelPath;

	protected $modelTemplatePath;

	private function setModelPath()
	{

		$this->modelPath = get_path(app_dir_name() . '/Models');

		return $this;

	}

	private function setModelTemplatePath()
	{

		$this->modelTemplatePath = stubs_path('Model');

		return $this;

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