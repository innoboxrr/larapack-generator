<?php

namespace Innoboxrr\LarapackGenerator\Tools\Export;

use Innoboxrr\LarapackGenerator\Tools\Tool;

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

		return $this->generate($this->exportTemplatePath . '/ExportTemplate.txt', $exportFile);

	}

	public function remove(string $ModelName)
	{

		$this->setUp($ModelName);

		$path = $this->exportPath . '/' . $this->PluralPascalCaseModelName . 'Exports.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;
		
	}

}