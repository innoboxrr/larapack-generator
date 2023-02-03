<?php

namespace Desar\Generator\Tools\Makers\ExportNotification;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class ExportNotificationMaker extends MakerTool
{

	protected $exportNotificationPath;

	protected $exportNotificationTemplatePath;

	protected $modelExportNotificationPath;

	private function setExportNotificationPath()
	{

		$this->exportNotificationPath = get_path(app_dir_name() . '/ExportNotifications');

	}

	private function setExportNotificationTemplatePath()
	{

		$this->exportNotificationTemplatePath = stubs_path('ExportNotification');

	}

	protected function setModelExportNotificationPath()
	{

		$path = $this->exportNotificationPath . '/' . $this->PascalCaseModelName;

		if (!file_exists($exportNotificationPath)) mkdir($exportNotificationPath, 0777, true);

		$this->modelExportNotificationPath = $path;

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