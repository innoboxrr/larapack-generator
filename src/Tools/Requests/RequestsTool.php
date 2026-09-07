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

    /**
     * Inyecta las reglas declaradas en el laraimport.
     *
     * Antes esto era un preg_replace sobre `public function rules(): array
     * { ... }` que sustituia el cuerpo entero. Tres problemas: `[^}]+` no
     * equilibra llaves (una regla con `Rule::in([...])` cortaba por el
     * primer `}`), el reemplazo no escapaba `$` ni `\` de la cadena de
     * sustitucion, y al reescribir el metodo completo se perdia la regla del
     * identificador que `authorize()` y `handle()` necesitan.
     *
     * Ahora se inyecta en un marcador, como hacen los demas tools: lo que el
     * stub declara fuera del marcador es intocable.
     */
    protected function processFileWithJson($filePath)
    {
        if (!in_array(basename($filePath), ['CreateRequest.php', 'UpdateRequest.php'])) return;

        $data = self::getJsonContent();
        $model = collect($data['models'])->where('name', $this->ModelName)->first();
        if (!$model) return;

        $requestType = str_contains(basename($filePath), 'CreateRequest') ? 'Create' : 'Update';
        $requestData = collect($model['requests'] ?? [])->firstWhere('name', $requestType);
        if (!$requestData) return;

        $rules = isset($requestData['rules']) && is_array($requestData['rules']) ? $requestData['rules'] : [];

        $fileContent = file_get_contents($filePath);

        // El stub de Update trae la regla del identificador por defecto, para
        // que el archivo sea correcto aunque nadie lo importe. Si el JSON
        // declara esa misma clave, manda la del JSON y la del stub sobra.
        $idKey = $this->snake_case_model_name . '_id';

        if (array_key_exists($idKey, $rules)) {
            // \R para no depender del fin de linea: los stubs estan en CRLF.
            $fileContent = preg_replace(
                '/^[ \t]*' . preg_quote("'{$idKey}' => 'required|numeric',", '/') . '\R/m',
                '',
                $fileContent
            );
        }

        return file_put_contents($filePath, str_replace(
            '//RULES//',
            $this->buildRules($rules),
            $fileContent
        ));
    }

    /**
     * Las reglas, ya como codigo PHP, con la sangria del marcador.
     *
     * @param  array<string, string|array<int, string>>  $rules
     */
    private function buildRules(array $rules): string
    {
        $lines = [];

        foreach ($rules as $field => $rule) {
            $lines[] = $this->export($field) . ' => ' . $this->export($rule) . ',';
        }

        // Sin reglas se deja el comentario del stub: el array queda vacio
        // pero el archivo sigue leyendose como una plantilla a rellenar.
        return $lines === [] ? '//' : implode("\n            ", $lines);
    }

    /**
     * var_export escapa comillas y barras invertidas, que aparecen en cuanto
     * una regla lleva un `regex:`.
     *
     * @param  string|array<int, string>  $value
     */
    private function export($value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map(fn ($item): string => $this->export($item), $value)) . ']';
        }

        return var_export((string) $value, true);
    }

}
