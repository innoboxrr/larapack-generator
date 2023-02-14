<?php

namespace Desar\Generator\Tools\Factory;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class FactoryTool extends Tool
{

	protected $factoryPath;

	protected $factoryTemplatePath;

	private function setFactoryPath()
	{

		$this->factoryPath = get_path('database/factories');

		return $this;

	}

	private function setFactoryTemplatePath()
	{

		$this->factoryTemplatePath = stubs_path('Factory');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setFactoryPath()
			->setFactoryTemplatePath()
			->addDatabaseNamespaceToComposerJson();

		$factoryFile = $this->factoryPath . '/' . $this->PascalCaseModelName . 'Factory.php';

		if(!file_exists($factoryFile)) {

			$templateFile = $this->factoryTemplatePath . '/FactoryTemplate.txt';

			if(copy($templateFile, $factoryFile)) {

				$this->replaceData($factoryFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

	private function addDatabaseNamespaceToComposerJson()
	{

		if(app_dir_name() == 'src') {

			$composerJsonPath = root_path() . '/composer.json';

		    $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);

			$baseNamespace = array_keys($composerJsonData['autoload']['psr-4'])[0];

			if (isset($composerJsonData['autoload']['psr-4'])) {

			    $composerJsonData['autoload']['psr-4'][$baseNamespace . 'Database\\Factories\\'] = 'database/factories/';

			} else {
			    
			    $composerJsonData['autoload'] = [
			    
			        'psr-4' => [
			    
			            $baseNamespace . 'Database\\Factories\\' => 'database/factories/',
			    
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

}