<?php

namespace Desar\Generator\Tools\Makers\Providers;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class AppServiceProviderMaker extends MakerTool
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

		if(!file_exists($appServiceProviderFile)) {

			$templateFile = $this->appServiceProviderTemplatePath . '/AppServiceProviderTemplate.txt';

			if(copy($templateFile, $appServiceProviderFile)) {

				$this->replaceData($appServiceProviderFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}