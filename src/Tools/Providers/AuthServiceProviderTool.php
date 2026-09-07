<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class AuthServiceProviderTool extends Tool
{

	protected $authServiceProviderPath;

	protected $authServiceProviderTemplatePath;

	private function setAuthServiceProviderPath()
	{

		$this->authServiceProviderPath = get_path(app_dir_name() . '/Providers');

		return $this;

	}

	private function setAuthServiceProviderTemplatePath()
	{

		$this->authServiceProviderTemplatePath = stubs_path('Providers');

		return $this;

	}

	public function create()
	{

		$this->init('')
			->setAuthServiceProviderPath()
			->setAuthServiceProviderTemplatePath()
			->addProvidersToComposerJson([$this->namespace . 'Providers\AuthServiceProvider']);

		$authServiceProviderFile = $this->authServiceProviderPath . '/AuthServiceProvider.php';

		return $this->generate($this->authServiceProviderTemplatePath . '/AuthServiceProviderTemplate.txt', $authServiceProviderFile);

	}

}