<?php

namespace Desar\Generator\Tools\Makers\Resource;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class ResourceGenerator extends MakerTool
{

	protected $resourcePath;

	protected $resourceTemplatePath;

	private function setResourcePath()
	{

		$this->resourcePath = get_path(app_dir_name() . '/Http/Resources/Models');

		return $this;

	}

	private function setResourceTemplatePath()
	{

		$this->resourceTemplatePath = get_path(app_dir_name() . '/Stubs/Resource');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setResourcePath()
			->setResourceTemplatePath();

		$resourceFile = $this->resourcePath . '/' . $this->PascalCaseModelName . 'Resource.php';

		if(!file_exists($resourceFile)) {

			$templateFile = $this->resourceTemplatePath . '/ResourceTemplate.txt';

			if(copy($templateFile, $resourceFile)) {

				$this->replaceData($resourceFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}