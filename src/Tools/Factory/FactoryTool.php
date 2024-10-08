<?php

namespace Innoboxrr\LarapackGenerator\Tools\Factory;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

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
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($factoryFile);
				}
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

	public function remove(string $ModelName)
	{
		$this->init($ModelName)
			->setFactoryPath();
		$path = $this->factoryPath . '/' . $this->PascalCaseModelName . 'Factory.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}

	protected function processFileWithJson($factoryFile)
	{
		$data = self::getJsonContent();
		$model = collect($data['models'])->where('name', $this->ModelName)->first();
		$fileContent = file_get_contents($factoryFile);
		$definitionContent = $this->generateFactoryDefinition($model['props']);
		$updatedFileContent = str_replace('//EDIT//', $definitionContent, $fileContent);
		file_put_contents($factoryFile, $updatedFileContent);
	}

	private function generateFactoryDefinition(array $props)
	{
		$definition = '';
		foreach ($props as $index => $prop) {

			if($index == 0) {
				$definition .= "{$this->getFakerDefinitionForProperty($prop)}\n";
			} else if($index == count($props) - 1) {
				$definition .= "            {$this->getFakerDefinitionForProperty($prop)}";
			} else {
				$definition .= "            {$this->getFakerDefinitionForProperty($prop)}\n";
			}
		}
		return $definition;
	}

	private function getFakerDefinitionForProperty(array $prop)
	{
		// Note: También se puede retornar un valor en función del nombre de la propiedad

		// Determinar el tipo de dato y generar el valor faker correspondiente
		switch ($prop['type']) {
			case 'string':
				return "'{$prop['name']}' => \$this->faker->sentence(),";  // Ejemplo: para strings
			case 'longText':
				return "'{$prop['name']}' => \$this->faker->text(),";  // Ejemplo: para textos largos
			case 'foreignId':
				return "'{$prop['name']}' => 1,";  // Ejemplo: para llaves foráneas
			case 'integer':
				return "'{$prop['name']}' => \$this->faker->randomNumber(),";  // Ejemplo: para enteros
			// Puedes agregar más casos para otros tipos de datos que quieras manejar
			default:
				return "'{$prop['name']}' => null,";  // Valor por defecto si no se reconoce el tipo
		}
	}

}