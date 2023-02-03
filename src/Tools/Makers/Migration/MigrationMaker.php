<?php

namespace Desar\Generator\Tools\Makers\Migration;

use Desar\Generator\Tools\MakerTool;
use Illuminate\Support\Facades\Schema;
use Desar\Generator\Exceptions\MakerException;

class MigrationMaker extends MakerTool
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

		$this->init($ModelName)
			->setMigrationPath()
			->setMigrationTemplatePath();

		$migrationFile = $this->migrationPath . '/' . date('Y_m_d_His') . '_create_' . $this->plural_snake_case_model_name . '_table.php';

		if(!file_exists($migrationFile) && !Schema::hasTable($this->plural_snake_case_model_name)) {

			$templateFile = $this->migrationTemplatePath . '/MigrationTemplate.txt';

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