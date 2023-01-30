<?php

namespace Desar\Generator\Tools\Makers\Controller;

use Desar\Generator\Tools\MakerTool;

class ControllerMaker extends MakerTool
{

	protected $controllerFilename;

	public function __construct(string $ModelName)
	{

		parent::__construct($ModelName);

		$this->setControllerFilename();

	}

	protected function setControllerFilename()
	{

		$this->controllerFilename = $this->PascalCaseModelName . 'Controller.php';

	}

	protected function create()
	{

		$controllerFile = $this->controllerPath . '/' . $this->controllerFilename;

		// Solo proceder en caso de los archivos no existan
		if(!file_exists($controllerFile)) {

			$templateFile = $this->controllerTemplatePath . '/ControllerTemplate.txt';

			if(copy($templateFile, $controllerFile)) {

				// Remplace dummy data
				$this->replaceData($controllerFile);

			} else {

				// PENDIENTE: Mandar un mensaje de error

			}

		} else {

			// PENDIENTE: Mandar un mensaje de que no el controlador ya existía

		}

	}

}