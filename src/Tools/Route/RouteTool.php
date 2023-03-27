<?php

namespace Innoboxrr\LarapackGenerator\Tools\Route;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class RouteTool extends Tool
{

	protected $apiRoutepath;

	protected $routeTemplatePath;

	private function setApiRoutepath()
	{

		$this->apiRoutepath = get_path('routes/api/models');

		return $this;

	}

	private function setRouteTemplatePath()
	{

		$this->routeTemplatePath = stubs_path('Route');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setApiRoutepath()
			->setRouteTemplatePath();

		$routeFile = $this->apiRoutepath . '/' . $this->snake_case_model_name . '.php';

		if(!file_exists($routeFile)) {

			$templateFile = $this->routeTemplatePath . '/RouteTemplate.txt';

			if(copy($templateFile, $routeFile)) {

				$this->replaceData($routeFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setApiRoutepath();

		$path = $this->apiRoutepath . '/' . $this->snake_case_model_name . '.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}