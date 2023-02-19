<?php

namespace Hrauvc\LarapackGenerator\Tools\Excel;

use Hrauvc\LarapackGenerator\Tools\Tool;
use Hrauvc\LarapackGenerator\Exceptions\MakerException;

class ExcelTool extends Tool
{

	protected $excelPath;

	protected $excelTemplatePath;

	private function setExcelPath()
	{

		$this->excelPath = get_path('resources/views/excel');

		return $this;

	}

	private function setExcelTemplatePath()
	{

		$this->excelTemplatePath = stubs_path('Excel');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setExcelPath()
			->setExcelTemplatePath();

		$excelFile = $this->excelPath . '/' . $this->snake_case_model_name . '.blade.php';

		if(!file_exists($excelFile)) {

			$templateFile = $this->excelTemplatePath . '/ExcelTemplate.txt';

			if(copy($templateFile, $excelFile)) {

				$this->replaceData($excelFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}