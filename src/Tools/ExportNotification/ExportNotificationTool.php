<?php

namespace Desar\Generator\Tools\ExportNotification;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

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

}