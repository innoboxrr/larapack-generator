<?php

namespace Desar\Generator\Tools\Makers\Register;

use Desar\Generator\Tools\MakerTool;
use Desar\Generator\Exceptions\MakerException;

class RegisterGenerator extends MakerTool
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

		$this->registerTemplatePath = get_path(app_dir_name() . '/Stubs/Register');

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