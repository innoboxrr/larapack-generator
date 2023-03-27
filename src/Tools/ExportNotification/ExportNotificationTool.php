<?php

namespace Innoboxrr\LarapackGenerator\Tools\ExportNotification;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

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

		$this->init($ModelName)
			->setExportNotificationPath()
			->setExportNotificationTemplatePath()
			->setModelExportNotificationPath();

		$exportNotificationFile = $this->modelExportNotificationPath . '/' . 'ExportNotification.php';

		if(!file_exists($exportNotificationFile)) {

			$templateFile = $this->exportNotificationTemplatePath . '/ExportNotificationTemplate.txt';

			if(copy($templateFile, $exportNotificationFile)) {

				$this->replaceData($exportNotificationFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setExportNotificationPath();

		$path = $this->exportNotificationPath . '/' . $this->PascalCaseModelName;

		return (file_exists($path)) ? $this->dropDir($path) : false;

	}

}