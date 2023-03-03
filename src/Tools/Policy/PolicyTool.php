<?php

namespace Innoboxrr\LarapackGenerator\Tools\Policy;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class PolicyTool extends Tool
{

	protected $policyPath;

	protected $policyTemplatePath;

	private function setPolicyPath()
	{

		$this->policyPath = get_path(app_dir_name() . '/Policies');

		return $this;

	}

	private function setPolicyTemplatePath()
	{

		$this->policyTemplatePath = stubs_path('Policy');

		return $this;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setPolicyPath()
			->setPolicyTemplatePath();

		$policyFile = $this->policyPath . '/' . $this->PascalCaseModelName . 'Policy.php';

		if(!file_exists($policyFile)) {

			$templateFile = $this->policyTemplatePath . '/PolicyTemplate.txt';

			if(copy($templateFile, $policyFile)) {

				$this->replaceData($policyFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}