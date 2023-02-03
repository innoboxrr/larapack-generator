<?php

namespace Desar\Generator\Tools\Makers\Export;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class ExportMaker extends MakerTool
{

	protected $exportPath;

	protected $exportTemplatePath;

	private function setExportPath()
	{

		$this->exportPath = get_path(app_dir_name() . '/Exports');

		return $this;

	}

	private function setExportTemplatePath()
	{

		$this->exportTemplatePath = stubs_path('Export');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setExportPath()
			->setExportTemplatePath();

		$exportFile = $this->exportPath . '/' . $this->PluralPascalCaseModelName . 'Exports.php';

		if(!file_exists($exportFile)) {

			$templateFile = $this->exportTemplatePath . '/ExportTemplate.txt';

			if(copy($templateFile, $exportFile)) {

				$this->replaceData($exportFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}