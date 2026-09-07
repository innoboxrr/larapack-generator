<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class RouteServiceProviderTool extends Tool
{

	protected $routeServiceProviderPath;

	protected $routeServiceProviderTemplatePath;

	private function setRouteServiceProviderPath()
	{

		$this->routeServiceProviderPath = get_path(app_dir_name() . '/Providers');

		return $this;

	}

	private function setRouteServiceProviderTemplatePath()
	{

		$this->routeServiceProviderTemplatePath = stubs_path('Providers');

		return $this;

	}

	public function create()
	{

		$this->init('')
			->setRouteServiceProviderPath()
			->setRouteServiceProviderTemplatePath()
			->addProvidersToComposerJson([$this->namespace . 'Providers\RouteServiceProvider']);

		$routeServiceProviderFile = $this->routeServiceProviderPath . '/RouteServiceProvider.php';

		return $this->generate($this->routeServiceProviderTemplatePath . '/RouteServiceProviderTemplate.txt', $routeServiceProviderFile);

	}

}