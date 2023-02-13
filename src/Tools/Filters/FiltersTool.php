<?php

namespace Desar\Generator\Tools\Filters;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class FiltersTool extends Tool
{

	protected $filtersPath;

	protected $filtersTemplatePath;

	protected $mainFiltersPath;

	protected $filters = [
		'CreationFilter',
		'EagerLoadingFilter',
		'IdFilter',
		'ManagedFilter',
		'UpdatedFilter',
	];

	private function setFiltersPath()
	{

		$this->filtersPath = get_path(app_dir_name() . '/Models/Filters');

		return $this;

	}

	private function setFiltersTemplatePath()
	{

		$this->filtersTemplatePath = stubs_path('Filters');

		return $this;

	}

	protected function setMainFiltersPath()
	{

		$path = $this->filtersPath . '/' . $this->PascalCaseModelName;

		if (!file_exists($path)) mkdir($path, 0777, true);

		$this->mainFiltersPath = $path;

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setFiltersPath()
			->setFiltersTemplatePath()
			->setMainFiltersPath();

		foreach($this->filters as $filter) {

			$this->createRequestFile($filter);

		}

		return true;

	}

	protected function createRequestFile($filterName)
	{

		$filterFile = $this->mainFiltersPath . '/' . $filterName . '.php';

		if(!file_exists($filterFile)) {

			$templateFile = $this->filtersTemplatePath . '/' . $filterName . 'Template.txt';

			if(copy($templateFile, $filterFile)) {

				$this->replaceData($filterFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}