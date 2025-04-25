<?php

namespace Innoboxrr\LarapackGenerator\Tools\PivotMigration;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class PivotMigrationTool extends Tool
{

	protected $migrationPath;
	protected $migrationTemplatePath;
	protected $migrationName;

	private function setMigrationPath()
	{
		$this->migrationPath = get_path('database/migrations');
		return $this;
	}

	private function setPivotMigrationTemplatePath()
	{
		$this->migrationTemplatePath = stubs_path('PivotMigration');
		return $this;
	}

	public function create(string $migrationName)
	{
		$this->migrationName = $migrationName;
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->setMigrationPath()
			->setPivotMigrationTemplatePath();
		$migrationFile = $this->migrationPath . '/' . date('Y_m_d_His') . '_create_' . $migrationName . '_table.php';
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/MigrationTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceMigrationData($migrationFile);
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

	public function remove(string $migrationName)
	{
		$this->migrationName = $migrationName;
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->setMigrationPath()
			->setPivotMigrationTemplatePath();
		$migrationFilename = date('Y_m_d_His') . '_drop_' . $migrationName . '_table.php';
		$migrationFile = $this->migrationPath . '/' . $migrationFilename;
		// Solo proceder en caso de los archivos no existan
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/DropTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceMigrationData($migrationFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	private function replaceMigrationData($migrationFile)
	{
		$content = file_get_contents($migrationFile);
		$content = str_replace("migration_name", $this->migrationName, $content);
		file_put_contents($migrationFile, $content);
	}

	protected function processFileWithJson($migrationFile)
	{
		$data = self::getJsonContent();
		$pivot = collect($data['pivots'])->where('name', $this->migrationName)->first();
		$fileContent = file_get_contents(filename: $migrationFile);
		$columnsSchema = $this->generateMigrationColumns($pivot['props']);
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

		// Agregar las propiedades adicionales como 'nullable', 'default', 'after', etc.
		if ($prop['nullable']) {
			$column .= "->nullable()";
		}
	
		if (!is_null($prop['default'])) {
			$column .= "->default('{$prop['default']}')";
		}
	
		/*
		if (!is_null($prop['after'])) {
			$column .= "->after('{$prop['after']}')";
		}
		*/
	
		// Agregar restricciones de clave foránea si las hay
		if ($prop['type'] === 'foreignId' && !is_null($prop['constraint'])) {
			$column .= "->constrained('{$prop['constraint']}')->onUpdate('cascade')->onDelete('cascade')";
		}
	
		return $column . ";";
	}
	
}