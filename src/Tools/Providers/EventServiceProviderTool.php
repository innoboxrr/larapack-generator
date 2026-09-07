<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class EventServiceProviderTool extends Tool
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

		return $this->generate($this->eventServiceProviderTemplatePath . '/EventServiceProviderTemplate.txt', $eventServiceProviderFile);

	}

}