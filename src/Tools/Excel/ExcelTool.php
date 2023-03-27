<?php

namespace Innoboxrr\LarapackGenerator\Tools\Excel;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

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

	private function setUp(string $ModelName)
	{

		$this->init($ModelName)
			->setExcelPath()
			->setExcelTemplatePath();

	}

	public function create(string $ModelName)
	{

		$this->setUp($ModelName);

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

	public function remove(string $ModelName)
	{

		$this->setUp($ModelName);

		$path = $this->excelPath . '/' . $this->snake_case_model_name . '.blade.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;
		
	}

}