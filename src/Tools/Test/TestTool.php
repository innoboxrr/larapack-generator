<?php

namespace Innoboxrr\LarapackGenerator\Tools\Test;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;

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
			->createTestUserClass()
			->createPhpUnitXmlFile()
			->addTestNamespaceToComposerJson()
			->createFeatureTest();

	}

	/**
	 * Lo que un proyecto necesita para tener tests antes de su primer modelo.
	 */
	public function createScaffold()
	{

		$this->init('')
			->setTestCasePath()
			->setTestTemplatePath()
			->createTestCaseClass()
			->createTestUserClass()
			->createPhpUnitXmlFile()
			->addTestNamespaceToComposerJson();

		return $this;

	}

	/**
	 * Copia una plantilla de test si el destino no existe, respetando la
	 * simulacion y dejando constancia: antes se copiaba en silencio, tambien con
	 * --dry-run, y el informe no la mencionaba.
	 */
	private function copyOnce(string $template, string $destination): bool
	{

		if (file_exists($destination)) {

			return false;

		}

		if (Generation::isDryRun()) {

			Generation::record('create', $destination, $template);

			return false;

		}

		if (! copy($template, $destination)) {

			throw MakerException::copyFailed($template, $destination);

		}

		Generation::record('create', $destination, $template);

		return true;

	}

	private function createTestCaseClass()
	{

		$testCaseFile = $this->testCasePath . '/TestCase.php';

		if ($this->copyOnce($this->testTemplatePath . '/TestCaseTemplate.txt', $testCaseFile)) {

			$this->replaceData($testCaseFile);

		}

		return $this;

	}

	/**
	 * El usuario con que se autentican los tests de un paquete. Una aplicacion
	 * ya tiene el suyo; un paquete no conoce el de quien lo instale.
	 */
	private function createTestUserClass()
	{

		$userFile = $this->testCasePath . '/User.php';

		if (app_dir_name() == 'src' && $this->copyOnce($this->testTemplatePath . '/TestUserTemplate.txt', $userFile)) {

			$this->replaceData($userFile);

		}

		return $this;

	}

	private function createPhpUnitXmlFile()
	{

		$phpunitFile = root_path() . '/phpunit.xml';

		// Un phpunit.xml.dist ya es la configuracion del proyecto; crear ademas
		// un phpunit.xml la taparia en silencio para quien lo tenga.
		if (file_exists(root_path() . '/phpunit.xml.dist')) {

			return $this;

		}

		if(!file_exists($phpunitFile)) {

			$templateFile = $this->testTemplatePath . '/PhpunitTemplate.txt';

			if($this->copyOnce($templateFile, $phpunitFile)) {

				// El directorio de cobertura depende de si es paquete (src) o proyecto (app).
				file_put_contents($phpunitFile, str_replace(
					'__SOURCE_DIR__',
					app_dir_name(),
					file_get_contents($phpunitFile)
				));

			}

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

			$templateName = (app_dir_name() == 'src') ? 
				'/TestPackageTemplate.txt' : 
				'/TestProjectTemplate.txt';

			$templateFile = $this->testTemplatePath . $templateName;

			if(copy($templateFile, $testFile)) {

				$this->applyBlocks($testFile);

				$this->replaceData($testFile);

			} else {

				throw MakerException::copyFailed($templateFile, $testFile);

			}

		} else {

			return false;

		}

		return true;
		
	}

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setTestPath();

		$path = $this->testPath . '/' . $this->PascalCaseModelName . 'EndpointsTest.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;
		
	}

}