<?php

namespace Desar\Generator\Tools\Requests;

use Desar\Generator\Tools\Tool;
use Desar\Generator\Exceptions\MakerException;

class RequestsTool extends Tool
{

	protected $requestPath;

	protected $requestsTemplatePath;

	protected $mainRequestsPath;

	protected $requests = [
		'CreateRequest',
		'DeleteRequest',
		'ExportRequest',
		'ForceDeleteRequest',
		'IndexRequest',
		'PoliciesRequest',
		'PolicyRequest',
		'RestoreRequest',
		'ShowRequest',
		'UpdateRequest'
	];

	private function setRequestPath()
	{

		$this->requestPath = get_path(app_dir_name() . '/Http/Requests');

		return $this;

	}

	private function setRequestsTemplatePath()
	{

		$this->requestsTemplatePath = stubs_path('Requests');

		return $this;

	}

	protected function setMainRequestsPath()
	{

		$path = $this->requestPath . '/' . $this->PascalCaseModelName;

		if (!file_exists($path)) mkdir($path, 0777, true);

		$this->mainRequestsPath = $path;

		return $this;

	}

	protected function createRequest($requestName)
	{

		$requestFile = $this->mainRequestsPath . '/' . $requestName . '.php';

		if(!file_exists($requestFile)) {

			$templateFile = $this->requestsTemplatePath . '/' . $requestName . '.txt';

			if(copy($templateFile, $requestFile)) {

				$this->replaceData($requestFile);

			} else {

				throw new MakerException;

			}

		} else {

			return false;

		}

		return true;

	}

	public function create(string $ModelName)
	{

		$this->init($ModelName)
			->setRequestPath()
			->setRequestsTemplatePath()
			->setMainRequestsPath();

		foreach($this->requests as $request) {

			$this->createRequest($request);

		}

	}

}