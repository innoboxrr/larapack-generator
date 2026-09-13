<?php

namespace Innoboxrr\LarapackGenerator\Tools\ExportNotification;

use Innoboxrr\LarapackGenerator\Support\Translations;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ExportNotificationTool extends Tool
{

	protected $exportNotificationPath;

	protected $exportNotificationTemplatePath;

	protected $modelExportNotificationPath;

	private function setExportNotificationPath()
	{

		$this->exportNotificationPath = get_path(app_dir_name() . '/Notifications');

		return $this;

	}

	private function setExportNotificationTemplatePath()
	{

		$this->exportNotificationTemplatePath = stubs_path('ExportNotification');

		return $this;

	}

	protected function setModelExportNotificationPath()
	{

		$path = $this->exportNotificationPath . '/' . $this->PascalCaseModelName;

		if (!file_exists($path)) mkdir($path, 0777, true);

		$this->modelExportNotificationPath = $path;

		return $this;

	}

	public function create(string $ModelName)
	{

		if (! $this->init($ModelName)->declares('export')) {
			return false;
		}

		$this->init($ModelName)
			->setExportNotificationPath()
			->setExportNotificationTemplatePath()
			->setModelExportNotificationPath();

		$exportNotificationFile = $this->modelExportNotificationPath . '/' . 'ExportNotification.php';

		$created = $this->generate($this->exportNotificationTemplatePath . '/ExportNotificationTemplate.txt', $exportNotificationFile);

		Translations::sync(root_path() . '/lang', [$exportNotificationFile], Translations::BACKEND, ['es']);

		return $created;

	}

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setExportNotificationPath();

		$path = $this->exportNotificationPath . '/' . $this->PascalCaseModelName;

		return (file_exists($path)) ? $this->dropDir($path) : false;

	}

}