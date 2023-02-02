<?php

namespace Desar\Generator\Tools\Makers\Route;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class RouteGenerator extends MakerTool
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

		$this->routeTemplatePath = get_path(app_dir_name() . '/Stubs/Route');

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

}