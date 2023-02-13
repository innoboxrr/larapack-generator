<?php

namespace Desar\Generator\Tools\Test;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class TestTool extends Tool
{

	protected $testPath;

	protected $testTemplatePath;

	protected $testCasePath;

	protected $testUnitPath;

	private function setTestPath()
	{

		$this->testPath = get_path('tests/Feature/Models');

		return $this;

	}

	private function setTestCasePath()
	{

		$this->testCasePath = get_path('tests');

		return $this;

	}

	private function setTestUnitPath()
	{

		$this->testUnitPath = get_path('tests/Unit');

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
			->setTestCasePath()
			->setTestUnitPath()
			->setTestTemplatePath()
			->createTestCaseClass()
			->createPhpUnitXmlFile()
			->addTestNamespaceToComposerJson()
			->createFeatureTest();

	}

	private function createTestCaseClass()
	{

		$testCaseFile = $this->testCasePath . '/TestCase.php';

		if(!file_exists($testCaseFile)) {

			$templateFile = $this->testTemplatePath . '/TestCaseTemplate.txt';

			if(copy($templateFile, $testCaseFile)) {

				$this->replaceData($testCaseFile);

			}

		}

		return $this;

	}

	private function createPhpUnitXmlFile()
	{

		$phpunitFile = root_path() . '/phpunit.xml';

		if(!file_exists($phpunitFile)) {

			$templateFile = $this->testTemplatePath . '/PhpunitTemplate.txt';

			copy($templateFile, $phpunitFile);

		}

		return $this;

	}

	private function addTestNamespaceToComposerJson()
	{

		if(app_dir_name() == 'src') {

			$composerJsonPath = root_path() . '/composer.json';

		    $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);

			$baseNamespace = array_keys($composerJsonData['autoload']['psr-4'])[0];

			if (isset($composerJsonData['autoload-dev']['psr-4'])) {

			    $composerJsonData['autoload-dev']['psr-4'][$baseNamespace . 'Tests\\'] = 'tests/';

			} else {
			    
			    $composerJsonData['autoload-dev'] = [
			    
			        'psr-4' => [
			    
			            $baseNamespace . 'Tests\\' => 'tests/',
			    
			        ],
			    
			    ];

			}

			file_put_contents(
				$composerJsonPath, 
				json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
			);

		}

		return $this;

	}

	private function createFeatureTest()
	{

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