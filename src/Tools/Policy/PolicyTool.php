<?php

namespace Innoboxrr\LarapackGenerator\Tools\Policy;

use Innoboxrr\LarapackGenerator\Tools\Tool;

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

		return $this->generate($this->policyTemplatePath . '/PolicyTemplate.txt', $policyFile);

	}

	public function remove(string $ModelName)
	{

		$this->init($ModelName)
			->setPolicyPath();

		$path = $this->policyPath . '/' . $this->PascalCaseModelName . 'Policy.php';

		return (file_exists($path)) ? $this->dropFile($path) : false;

	}

}