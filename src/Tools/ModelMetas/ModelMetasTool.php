<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelMetas;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelMetasTool extends Tool
{

	protected $modelMetasPath;

	protected $migrationMetasPath;

	protected $modelMetasTemplatePath;

	protected $migrationMetasTemplatePath;

	private function setModelMetasPath()
	{
		$this->modelMetasPath = get_path(app_dir_name() . '/Models');
		return $this;
	}

	private function setMigrationMetasPath()
	{
		$this->migrationMetasPath = get_path('database/migrations');
		return $this;
	}

	private function setModelMetasTemplatePath()
	{
		$this->modelMetasTemplatePath = stubs_path('ModelMetas');
		return $this;
	}

	private function setMigrationMetasTemplatePath()
	{
		$this->migrationMetasTemplatePath = stubs_path('MigrationMetas');
		return $this;
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName)
			->setModelMetasPath()
			->setMigrationMetasPath()
			->setModelMetasTemplatePath()
			->setMigrationMetasTemplatePath();

		$this->createModelMetas();
		$this->createMigrationMetas();
		return true;
	}

	private function createModelMetas()
	{
		$modelMetasFile = $this->modelMetasPath . '/' . $this->PascalCaseModelName . 'ModelMetas.php';
		if(!file_exists($modelMetasFile)) {
			$templateFile = $this->modelMetasTemplatePath . '/ModelMetasTemplate.txt';
			if(copy($templateFile, $modelMetasFile)) {
				$this->replaceData($modelMetasFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
	}

	private function createMigrationMetas()
	{
		date_default_timezone_set('UTC');
		$migrationMetasFile = $this->migrationMetasPath . '/' . date('Y_m_d_His') . '_create_' . $this->snake_case_model_name . '_metas_table.php';
		if(!file_exists($migrationMetasFile)) {
			$templateFile = $this->migrationMetasTemplatePath . '/MigrationTemplate.txt';
			if(copy($templateFile, $migrationMetasFile)) {
				$this->replaceData($migrationMetasFile);
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
			->setModelMetasPath()
			->setMigrationMetasPath();

		// Eliminar el modelo y crear la migración de eliminación
		$this->removeModelMetas();
		$this->removeMigrationMetas();
	}

	private function removeModelMetas()
	{
		$path = $this->modelMetasPath . '/' . $this->PascalCaseModelName . 'ModelMetas.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}

	private function removeMigrationMetas()
	{
		date_default_timezone_set('UTC');
		$migrationFilename = date('Y_m_d_His') . '_drop_' . $this->snake_case_model_name . '_metas_table.php';
		$migrationFile = $this->migrationMetasPath . '/' . $migrationFilename;
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationMetasTemplatePath . '/DropTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceData($migrationFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

}