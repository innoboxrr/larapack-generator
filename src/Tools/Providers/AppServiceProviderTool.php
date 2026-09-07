<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class AppServiceProviderTool extends Tool
{

	protected $appServiceProviderPath;

	protected $appServiceProviderTemplatePath;

	private function setAppServiceProviderPath()
	{

		$this->appServiceProviderPath = get_path(app_dir_name() . '/Providers');

		return $this;

	}

	private function setAppServiceProviderTemplatePath()
	{

		$this->appServiceProviderTemplatePath = stubs_path('Providers');

		return $this;

	}

	public function create()
	{

		$this->init('')
			->setAppServiceProviderPath()
			->setAppServiceProviderTemplatePath()
			->addProvidersToComposerJson([$this->namespace . 'Providers\AppServiceProvider']);

		$appServiceProviderFile = $this->appServiceProviderPath . '/AppServiceProvider.php';

		return $this->generate($this->appServiceProviderTemplatePath . '/AppServiceProviderTemplate.txt', $appServiceProviderFile);

	}

}