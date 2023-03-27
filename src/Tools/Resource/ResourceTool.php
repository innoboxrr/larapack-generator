<?php

namespace Innoboxrr\LarapackGenerator\Tools\Resource;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ResourceTool extends Tool
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

		$this->resourceTemplatePath = stubs_path('Resource');

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

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setResourcePath();

		$path = $this->resourcePath . '/' . $this->PascalCaseModelName . 'Resource.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}