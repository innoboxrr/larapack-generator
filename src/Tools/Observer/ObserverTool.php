<?php

namespace Innoboxrr\LarapackGenerator\Tools\Observer;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ObserverTool extends Tool
{

	protected $observerPath;

	protected $observerTemplatePath;

	private function setObserverPath()
	{

		$this->observerPath = get_path(app_dir_name() . '/Observers');

		return $this;

	}

	private function setObserverTemplatePath()
	{

		$this->observerTemplatePath = stubs_path('Observer');

		return $this;

	}

	public function create(string $ObserverName)
	{

		$this->init($ObserverName)
			->setObserverPath()
			->setObserverTemplatePath();

		$observerFile = $this->observerPath . '/' . $this->PascalCaseModelName . 'Observer.php';

		if(!file_exists($observerFile)) {

			$templateFile = $this->observerTemplatePath . '/ObserverTemplate.txt';

			if(copy($templateFile, $observerFile)) {

				$this->replaceData($observerFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}