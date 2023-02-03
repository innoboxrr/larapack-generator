<?php

namespace Desar\Generator\Tools\Makers\Policy;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class PolicyMaker extends MakerTool
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