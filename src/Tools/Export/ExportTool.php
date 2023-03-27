<?php

namespace Innoboxrr\LarapackGenerator\Tools\Export;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ExportTool extends Tool
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

	private function setUp(string $ModelName)
	{

		$this->init($ModelName)
			->setExportPath()
			->setExportTemplatePath();

	}

	public function create(string $ModelName)
	{

		$this->setUp($ModelName);

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

	public function remove(string $ModelName)
	{

		$this->setUp($ModelName);

		$exportFile = $this->exportPath . '/' . $this->PluralPascalCaseModelName . 'Exports.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;
		
	}

}