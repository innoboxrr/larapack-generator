<?php

namespace Desar\Generator\Tools\Makers\Test;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class TestMaker extends MakerTool
{

	protected $testPath;

	protected $testTemplatePath;

	private function setTestPath()
	{

		$this->testPath = get_path('tests/Feature/Models');

		return $this;

	}

	private function setTestTemplatePath()
	{

		$this->testTemplatePath = stubs_path('Test');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setTestPath()
			->setTestTemplatePath();

		$testFile = $this->testPath . '/' . $this->PascalCaseModelName . 'EndpointsTest.php';

		if(!file_exists($testFile)) {

			$templateFile = $this->testTemplatePath . '/TestTemplate.txt';

			if(copy($templateFile, $testFile)) {

				$this->replaceData($testFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}