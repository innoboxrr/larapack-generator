<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Illuminate\Support\Facades\Schema;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class MigrationTool extends Tool
{

	protected $migrationPath;
	protected $migrationTemplatePath;

	private function setMigrationPath()
	{
		$this->migrationPath = get_path('database/migrations');
		return $this;
	}

	private function setMigrationTemplatePath()
	{
		$this->migrationTemplatePath = stubs_path('Migration');
		return $this;
	}

	public function create(string $ModelName)
	{
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->init($ModelName)
			->setMigrationPath()
			->setMigrationTemplatePath();

		sleep(2);
	
		$migrationFile = $this->migrationPath . '/' . date('Y_m_d_His') . '_create_' . $this->plural_snake_case_model_name . '_table.php';
		// PENDIENTE: Cambiar esto para que en lugar de esta validación verifique si no existe esta misma clase en las migraciones de la aplicación
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/MigrationTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceData($migrationFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($migrationFile);
				}
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
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->init($ModelName)
			->setMigrationPath()
			->setMigrationTemplatePath();
		$migrationFilename = date('Y_m_d_His') . '_drop_' . $this->plural_snake_case_model_name . '_table.php';
		$migrationFile = $this->migrationPath . '/' . $migrationFilename;
		// Solo proceder en caso de los archivos no existan
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/DropTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				// Remplace dummy data
				$this->replaceData($migrationFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	protected function processFileWithJson($migrationFile)
	{
		$data = self::getJsonContent();
		$model = collect($data['models'])->where('name', $this->ModelName)->first();
		$fileContent = file_get_contents($migrationFile);
		$columnsSchema = $this->generateMigrationColumns($model['props']);
		$updatedFileContent = str_replace('//EDIT//', $columnsSchema, $fileContent);
		file_put_contents($migrationFile, $updatedFileContent);
	}
	
	private function generateMigrationColumns(array $props)
	{
		$columns = '';
		foreach ($props as $index => $prop) {
			$columnDefinition = $this->getColumnDefinition($prop);
			if($index == 0) {
				$columns .= "{$columnDefinition}\n";
			} else if($index == count($props) - 1) {
				$columns .= "            {$columnDefinition}";
			} else {
				$columns .= "            {$columnDefinition}\n";
			}
		}	
		return $columns;
	}
	
	private function getColumnDefinition(array $prop)
	{
		$column = "\$table->{$prop['type']}('{$prop['name']}')";

		if ($prop['nullable']) {
			$column .= "->nullable()";
		}

		if (array_key_exists('default', $prop) && $prop['default'] !== null) {

			$default = $prop['default'];

			// Determinar tipo de valor default
			if (is_bool($default)) {
				$default = $default ? 'true' : 'false';
			} elseif (is_numeric($default)) {
				// se deja tal cual
			} else {
				$default = "'{$default}'"; // string u otro tipo
			}

			$column .= "->default({$default})";
		}

		if ($prop['type'] === 'foreignId' && !is_null($prop['constraint'])) {
			$column .= "->constrained('{$prop['constraint']}')->onUpdate('cascade')->onDelete('cascade')";
		}

		return $column . ";";
	}

	
}