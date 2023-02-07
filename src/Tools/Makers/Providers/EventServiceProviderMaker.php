<?php

namespace Desar\Generator\Tools\Makers\Providers;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class EventServiceProviderMaker extends MakerTool
{

	protected $eventServiceProviderPath;

	protected $eventServiceProviderTemplatePath;

	private function setEventServiceProviderPath()
	{

		$this->eventServiceProviderPath = get_path(app_dir_name() . '/Providers');

		return $this;

	}

	private function setEventServiceProviderTemplatePath()
	{

		$this->eventServiceProviderTemplatePath = stubs_path('Providers');

		return $this;

	}

	public function create()
	{

		$this->init('')
			->setEventServiceProviderPath()
			->setEventServiceProviderTemplatePath()
			->addProvidersToComposerJson([$this->namespace . 'Providers\EventServiceProvider']);

		$eventServiceProviderFile = $this->eventServiceProviderPath . '/EventServiceProvider.php';

		if(!file_exists($eventServiceProviderFile)) {

			$templateFile = $this->eventServiceProviderTemplatePath . '/EventServiceProviderTemplate.txt';

			if(copy($templateFile, $eventServiceProviderFile)) {

				$this->replaceData($eventServiceProviderFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}