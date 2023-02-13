<?php

namespace Desar\Generator\Tools\Register;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class RegisterTool extends Tool
{

	protected $registerPath;

	protected $registerTemplatePath;

	private function setRegisterPath()
	{

		$this->registerPath = get_path('storage/registers');

		return $this;

	}

	private function setRegisterTemplatePath()
	{

		$this->registerTemplatePath = stubs_path('Register');

		return $this;

	}
	
	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setRegisterPath()
			->setRegisterTemplatePath();

		$registerFile = $this->registerPath . '/' . $this->PascalCaseModelName . 'Register.txt';

		if(!file_exists($registerFile)) {

			$templateFile = $this->registerTemplatePath . '/RegisterTemplate.txt';

			if(copy($templateFile, $registerFile)) {

				$this->replaceData($registerFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

}