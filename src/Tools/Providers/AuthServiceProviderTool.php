<?php

namespace Hrauvc\LarapackGenerator\Tools\Providers;

use Hrauvc\LarapackGenerator\Tools\Tool;
use Hrauvc\LarapackGenerator\Exceptions\MakerException;

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

		if(!file_exists($authServiceProviderFile)) {

			$templateFile = $this->authServiceProviderTemplatePath . '/AuthServiceProviderTemplate.txt';

			if(copy($templateFile, $authServiceProviderFile)) {

				$this->replaceData($authServiceProviderFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}