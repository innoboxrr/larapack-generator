<?php

namespace Innoboxrr\LarapackGenerator\Tools\Route;

use Innoboxrr\LarapackGenerator\Tools\Tool;

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

		if (! $this->init($ModelName)->declaresAnyAction()) {
			return false;
		}

		$this->init($ModelName)
			->setApiRoutepath()
			->setRouteTemplatePath();

		$routeFile = $this->apiRoutepath . '/' . $this->snake_case_model_name . '.php';

		return $this->generate($this->routeTemplatePath . '/RouteTemplate.txt', $routeFile);

	}

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setApiRoutepath();

		$path = $this->apiRoutepath . '/' . $this->snake_case_model_name . '.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}