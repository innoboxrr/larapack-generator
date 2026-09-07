<?php

namespace Innoboxrr\LarapackGenerator\Tools\Requests;

use Innoboxrr\LarapackGenerator\Tools\Tool;

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

        return $this->generate($this->requestsTemplatePath . '/' . $requestName . '.txt', $requestFile);
    }

    public function create(string $ModelName)
    {
        $this->init($ModelName)
            ->setRequestPath()
            ->setRequestsTemplatePath()
            ->setMainRequestsPath();

        foreach ($this->requests as $request) {
            $this->createRequest($request);
        }
    }

    public function remove(string $ModelName)
    {
        $this->init($ModelName)
            ->setRequestPath();

        $path = $this->requestPath . '/' . $this->PascalCaseModelName;

        return (file_exists($path)) ? $this->dropDir($path) : false;
    }

    protected function processFileWithJson($filePath)
    {
        if (!in_array(basename($filePath), ['CreateRequest.php', 'UpdateRequest.php'])) return;

        $data = self::getJsonContent();
        $model = collect($data['models'])->where('name', $this->ModelName)->first();
        if (!$model) return;

        $requestType = str_contains($filePath, 'CreateRequest') ? 'Create' : 'Update';
        $requestData = collect($model['requests'])->firstWhere('name', $requestType);
        if (!$requestData) return;

        $rulesArray = isset($requestData['rules']) && is_array($requestData['rules']) ? $requestData['rules'] : [];

        $formattedRules = "[\n";
        foreach ($rulesArray as $key => $value) {
            $formattedRules .= "            '{$key}' => '{$value}',\n";
        }
        $formattedRules .= "        ]";

        $fileContent = file_get_contents($filePath);

        $fileContent = preg_replace(
            '/public function rules\(\): array\s*\{[^}]+\}/s',
            "public function rules(): array\n    {\n        return {$formattedRules};\n    }",
            $fileContent
        );

        file_put_contents($filePath, $fileContent);
    }

}
