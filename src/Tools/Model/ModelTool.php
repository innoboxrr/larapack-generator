<?php

namespace Innoboxrr\LarapackGenerator\Tools\Model;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelTool extends Tool
{

	protected $modelPath;

	protected $modelTemplatePath;

	private function setModelPath()
	{
		$this->modelPath = get_path(app_dir_name() . '/Models');
		return $this;
	}

	private function setModelTemplatePath()
	{
		$this->modelTemplatePath = stubs_path('Model');
		return $this;
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName)
			->setModelPath()
			->setModelTemplatePath();

		$modelFile = $this->modelPath . '/' . $this->PascalCaseModelName . '.php';
		if(!file_exists($modelFile)) {
			$templateFile = $this->modelTemplatePath . '/ModelTemplate.txt';
			if(copy($templateFile, $modelFile)) {
				$this->replaceData($modelFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($modelFile);
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
		$this->init($ModelName)
			->setModelPath();
		$path = $this->modelPath . '/' . $this->PascalCaseModelName . '.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}

	protected function processFileWithJson($modelFile)
	{
		// Obtener el contenido JSON
		$data = self::getJsonContent();

		// Recuperar la información del modelo
		$model = collect($data['models'])->where('name', $this->ModelName)->first();

		// Recuperar el contenido del archivo del modelo
		$fileContent = file_get_contents($modelFile);

		// Obtener los valores para los diferentes secciones de la plantilla
		$fillable = $this->generateListFromProps($model['props'], 'fillable');
		$creatable = $this->generateListFromProps($model['props'], 'creatable');
		$updatable = $this->generateListFromProps($model['props'], 'updatable');
		$casts = $this->generateCasts($model['props']);
		$editableMetas = $this->generateEditableMetas($model['editable_metas']);
		$exportCols = $this->generateExportCols($model['props']);
		$loadableRelations = $this->generateLoadableRelations($model['load_relations']);
		$loadableCounts = $this->generateLoadableCounts($model['load_counts']);

		// Reemplazar los marcadores en el archivo del modelo
		$updatedFileContent = str_replace('//FILLABLE//', $fillable, $fileContent);
		$updatedFileContent = str_replace('//CREATABLE//', $creatable, $updatedFileContent);
		$updatedFileContent = str_replace('//UPDATABLE//', $updatable, $updatedFileContent);
		$updatedFileContent = str_replace('//CASTS//', $casts, $updatedFileContent);
		$updatedFileContent = str_replace('//EDITABLEMETAS//', $editableMetas, $updatedFileContent);
		$updatedFileContent = str_replace('//EXPORTCOLS//', $exportCols, $updatedFileContent);
		$updatedFileContent = str_replace('//LOADABLERELATIONS//', $loadableRelations, $updatedFileContent);
		$updatedFileContent = str_replace('//LOADABLECOUNTS//', $loadableCounts, $updatedFileContent);

		// Guardar el archivo de nuevo con las modificaciones
		file_put_contents($modelFile, $updatedFileContent);
	}

	private function generateListFromProps(array $props, $field)
	{
		$list = [];

		foreach ($props as $prop) {
			if ($prop[$field]) {
				$list[] = "'{$prop['name']}'";
			}
		}

		return implode(', ', $list);
	}

	private function generateCasts(array $props)
	{
		$casts = [];

		foreach ($props as $prop) {
			if (!is_null($prop['cast'])) {
				$casts[] = "'{$prop['name']}' => '{$prop['cast']}'";
			}
		}

		return implode(', ', $casts);
	}

	private function generateEditableMetas(array $editableMetas)
	{
		if (empty($editableMetas)) {
			return '';
		}

		return implode(', ', array_map(fn($meta) => "'{$meta}'", $editableMetas));
	}

	private function generateExportCols(array $props)
	{
		$exportCols = [];

		foreach ($props as $prop) {
			if ($prop['exports_cols']) {
				$exportCols[] = "'{$prop['name']}'";
			}
		}

		return implode(', ', $exportCols);
	}

	private function generateLoadableRelations(array $loadRelations)
	{
		if (empty($loadRelations)) {
			return '';
		}

		return implode(', ', array_map(fn($relation) => "'{$relation['name']}'", $loadRelations));
	}

	private function generateLoadableCounts(array $loadCounts)
	{
		if (empty($loadCounts)) {
			return '';
		}

		return implode(', ', array_map(fn($count) => "'{$count}'", $loadCounts));
	}


}