<?php

namespace Innoboxrr\LarapackGenerator\Tools\Events;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class EventsTool extends Tool
{

	private $eventsPath;
	private $eventsTemplatePath;
	private $mainEventsPath;
	private $modelEventsPath;
	private $modelListenersPath;
	private $events = [
		'CreateEvent' => [
			'DefaultOperation'
		],
		'DeleteEvent' => [
			'DefaultOperation'
		],
		'ExportEvent' => [
			'DefaultOperation',
			'SendExportNotification'
		],
		'ForceDeleteEvent' => [
			'DefaultOperation'
		],
		'RestoreEvent' => [
			'DefaultOperation'
		],
		'UpdateEvent' => [
			'DefaultOperation'
		],
	];

	private function setEventsPath()
	{
		$this->eventsPath = get_path(app_dir_name() . '/Http/Events');
		return $this;
	}

	private function setEventsTemplatePath()
	{
		$this->eventsTemplatePath = stubs_path('Events');
		return $this;
	}

	private function setMainEventsPath()
	{
		$path = $this->eventsPath . '/' . $this->PascalCaseModelName;
		if (!file_exists($path)) mkdir($path, 0777, true);
		$this->mainEventsPath = $path;
		return $this;
	}

	private function setModelEventsPath()
	{
		$path = $this->mainEventsPath . '/' . 'Events';
		if (!file_exists($path)) mkdir($path, 0777, true);
		$this->modelEventsPath = $path;
		return $this;
	}

	private function setModelListenersPath()
	{
		$path = $this->mainEventsPath . '/' . 'Listeners';
		if (!file_exists($path)) mkdir($path, 0777, true);
		$this->modelListenersPath = $path;
		return $this;
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName)
			->setEventsPath()
			->setEventsTemplatePath()
			->setMainEventsPath()
			->setModelEventsPath()
			->setModelListenersPath()
			->createEvents();
	}

	private function createEvents()
	{	
		foreach($this->events as $event => $listeners) {
			$this->createEvent($event);
			foreach($listeners as $listener) {
				$this->createListener($event, $listener);
			}
		}
	}

	private function createEvent($eventName)
	{
		$eventFile = $this->mainEventsPath . '/Events/' . $eventName . '.php';
		if(!file_exists($eventFile)) {
			$templateFile = $this->eventsTemplatePath . '/Events/' . $eventName . '.txt';
			if(copy($templateFile, $eventFile)) {
				$this->replaceData($eventFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	private function createListener($eventName, $listenerName)
	{
		$listenerPath = $this->createEventListenerDir($eventName);
		$listenerFile = $listenerPath . '/' . $listenerName . '.php';
		if(!file_exists($listenerFile)) {
			$templateFile = $this->eventsTemplatePath . '/Listeners/' . $eventName . '/' . $listenerName . '.txt';
			if(copy($templateFile, $listenerFile)) {
				$this->replaceData($listenerFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	private function createEventListenerDir($eventName)
	{
		$path = $this->mainEventsPath . '/Listeners/' . $eventName;
		if (!file_exists($path)) mkdir($path, 0777, true);
		return $path;
	}

	public function remove(string $ModelName)
	{
		$this->init($ModelName)
			->setEventsPath();
		$path = $this->eventsPath . '/' . $this->PascalCaseModelName;
		return (file_exists($path)) ? $this->dropDir($path) : false;
	}

}