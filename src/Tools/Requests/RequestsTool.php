<?php

namespace Innoboxrr\LarapackGenerator\Tools\Requests;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class RequestsTool extends Tool implements ModelArtifact
{
    protected string $requestPath = '';

    protected string $requestsTemplatePath = '';

    protected string $mainRequestsPath = '';

    /** @var list<string> */
    protected array $requests = [
        'CreateRequest',
        'DeleteRequest',
        'ExportRequest',
        'ForceDeleteRequest',
        'IndexRequest',
        'PoliciesRequest',
        'PolicyRequest',
        'RestoreRequest',
        'ShowRequest',
        'UpdateRequest',
        'BulkUpdateRequest',
        'BulkDeleteRequest',
    ];

    private function setRequestPath(): static
    {
        $this->requestPath = get_path(app_dir_name().'/Http/Requests');

        return $this;
    }

    private function setRequestsTemplatePath(): static
    {
        $this->requestsTemplatePath = stubs_path('Requests');

        return $this;
    }

    protected function setMainRequestsPath(): static
    {
        $path = $this->requestPath.'/'.$this->PascalCaseModelName;
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }
        $this->mainRequestsPath = $path;

        return $this;
    }

    protected function createRequest(string $requestName): bool
    {
        $requestFile = $this->mainRequestsPath.'/'.$requestName.'.php';

        return $this->generate($this->requestsTemplatePath.'/'.$requestName.'.txt', $requestFile);
    }

    public function create(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setRequestPath()
            ->setRequestsTemplatePath()
            ->setMainRequestsPath();

        $written = false;

        foreach ($this->requests as $request) {
            if (! $this->declares(lcfirst(substr($request, 0, -strlen('Request'))))) {
                continue;
            }

            $written = $this->createRequest($request) || $written;
        }

        return $written;
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setRequestPath();

        $path = $this->requestPath.'/'.$this->PascalCaseModelName;

        return $this->dropModelDir($path);
    }

    /**
     * `policies` responde por cada método del controlador con la habilidad
     * que le toca, y la interfaz habilita cada acción por esa respuesta. Un
     * endpoint que pregunta por otra habilidad (`archive` por `update`) se
     * consultaba por su nombre, que la política no tiene: salía siempre
     * deshabilitado, o habilitado sólo para quien pasa por before().
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        if ($marker !== 'ENDPOINT_POLICY_MAPPING') {
            return [];
        }

        $lines = [];

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint['ability'] !== null && $endpoint['ability'] !== $endpoint['name']) {
                $lines[] = var_export((string) $endpoint['name'], true).' => '.var_export((string) $endpoint['ability'], true).',';
            }
        }

        if ($lines !== []) {
            array_unshift($lines, '// Los endpoints que preguntan por la habilidad de otra acción.');
        }

        return $lines;
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
    protected function processFileWithJson(string $filePath): void
    {
        if (! in_array(basename($filePath), ['CreateRequest.php', 'UpdateRequest.php'])) {
            return;
        }

        $model = $this->jsonModel();
        if (! $model) {
            return;
        }

        $requestType = str_contains(basename($filePath), 'CreateRequest') ? 'Create' : 'Update';
        $requestData = self::firstNamed($model['requests'] ?? [], $requestType);
        if (! $requestData) {
            return;
        }

        $rules = isset($requestData['rules']) && is_array($requestData['rules']) ? $requestData['rules'] : [];

        $fileContent = self::contents($filePath);

        // El stub de Update trae la regla del identificador por defecto, para
        // que el archivo sea correcto aunque nadie lo importe. Si el JSON
        // declara esa misma clave, manda la del JSON y la del stub sobra.
        $idKey = $this->snake_case_model_name.'_id';

        if (array_key_exists($idKey, $rules)) {
            // \R para no depender del fin de linea: los stubs estan en CRLF.
            $fileContent = preg_replace(
                '/^[ \t]*'.preg_quote("'{$idKey}' => 'required|numeric',", '/').'\R/m',
                '',
                $fileContent
            ) ?? throw MakerException::rewriteFailed($filePath);
        }

        Files::write($filePath, str_replace(
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
            $lines[] = $this->export($field).' => '.$this->export($rule).',';
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
            return '['.implode(', ', array_map(fn ($item): string => $this->export($item), $value)).']';
        }

        return var_export((string) $value, true);
    }
}
