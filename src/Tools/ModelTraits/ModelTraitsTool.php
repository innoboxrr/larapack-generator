<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelTraits;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelTraitsTool extends Tool
{

	protected $modelTraitsPath;

	protected $modelTraitsTemplatePath;

	protected $assignmentsPath;

	protected $mutatorsPath;

	protected $operationsPath;

	protected $queriesPath;

	protected $relationsPath;

	protected $storagePath;

	private function setModelTraitsPath()
	{

		$this->modelTraitsPath = get_path(app_dir_name() . '/Models/Traits');

		return $this;

	}

	private function setModelTraitsTemplatePath()
	{

		$this->modelTraitsTemplatePath = stubs_path('ModelTraits');

		return $this;

	}

	protected function setTraitsPath()
	{

		$this->assignmentsPath = $this->modelTraitsPath . '/Assignments';

		if (!file_exists($this->assignmentsPath)) mkdir($this->assignmentsPath, 0777, true);


		$this->mutatorsPath = $this->modelTraitsPath . '/Mutators';

		if (!file_exists($this->mutatorsPath)) mkdir($this->mutatorsPath, 0777, true);

		
		$this->operationsPath = $this->modelTraitsPath . '/Operations';

		if (!file_exists($this->operationsPath)) mkdir($this->operationsPath, 0777, true);

		
		$this->relationsPath = $this->modelTraitsPath . '/Relations';

		if (!file_exists($this->relationsPath)) mkdir($this->relationsPath, 0777, true);


		$this->storagePath = $this->modelTraitsPath . '/Storage';

		if (!file_exists($this->storagePath)) mkdir($this->storagePath, 0777, true);

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setModelTraitsPath()
			->setModelTraitsTemplatePath()
			->setTraitsPath()
			->createTraits();

		return true;

	}

	private function createTraits()
	{

		$this->createAssignmentTrait()
			->createMutatorsTrait()
			->createOperationsTrait()
			->createRelationsTrait()
			->createStorageTrait();

		return $this;

	}

	private function createAssignmentTrait()
	{

		$assignmentTraitFile = $this->modelTraitsPath . '/Assignments/' . $this->PascalCaseModelName . 'Assignment.php';

		if(!file_exists($assignmentTraitFile)) {

			$templateFile = $this->modelTraitsTemplatePath . '/Assignments/AssignmentTemplate.txt';

			if(copy($templateFile, $assignmentTraitFile)) {

				$this->replaceData($assignmentTraitFile);

			} else {

				throw new MakerException;

			}

		} 

		return $this;

	}

	private function createMutatorsTrait()
	{

		$mutatorsTraitFile = $this->modelTraitsPath . '/Mutators/' . $this->PascalCaseModelName . 'Mutators.php';

		if(!file_exists($mutatorsTraitFile)) {

			$templateFile = $this->modelTraitsTemplatePath . '/Mutators/MutatorsTemplate.txt';

			if(copy($templateFile, $mutatorsTraitFile)) {

				$this->replaceData($mutatorsTraitFile);

			} else {

				throw new MakerException;

			}

		} 

		return $this;

	}

	private function createOperationsTrait()
	{

		$operationsTraitFile = $this->modelTraitsPath . '/Operations/' . $this->PascalCaseModelName . 'Operations.php';

		if(!file_exists($operationsTraitFile)) {

			$templateFile = $this->modelTraitsTemplatePath . '/Operations/OperationsTemplate.txt';

			if(copy($templateFile, $operationsTraitFile)) {

				$this->replaceData($operationsTraitFile);

			} else {

				throw new MakerException;

			}

		} 

		return $this;

	}

	private function createRelationsTrait()
	{

		$relationsTraitFile = $this->modelTraitsPath . '/Relations/' . $this->PascalCaseModelName . 'Relations.php';

		if(!file_exists($relationsTraitFile)) {

			$templateFile = $this->modelTraitsTemplatePath . '/Relations/RelationsTemplate.txt';

			if(copy($templateFile, $relationsTraitFile)) {

				$this->replaceData($relationsTraitFile);

			} else {

				throw new MakerException;

			}

		} 

		return $this;

	}

	private function createStorageTrait()
	{

		$storageTraitFile = $this->modelTraitsPath . '/Storage/' . $this->PascalCaseModelName . 'Storage.php';

		if(!file_exists($storageTraitFile)) {

			$templateFile = $this->modelTraitsTemplatePath . '/Storage/StorageTemplate.txt';

			if(copy($templateFile, $storageTraitFile)) {

				$this->replaceData($storageTraitFile);

			} else {

				throw new MakerException;

			}

		} 

		return $this;

	}

}