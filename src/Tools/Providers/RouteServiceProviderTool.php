<?php

namespace Hrauvc\LarapackGenerator\Tools\Providers;

use Hrauvc\LarapackGenerator\Tools\Tool;
use Hrauvc\LarapackGenerator\Exceptions\MakerException;

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

		if(!file_exists($routeServiceProviderFile)) {

			$templateFile = $this->routeServiceProviderTemplatePath . '/RouteServiceProviderTemplate.txt';

			if(copy($templateFile, $routeServiceProviderFile)) {

				$this->replaceData($routeServiceProviderFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}