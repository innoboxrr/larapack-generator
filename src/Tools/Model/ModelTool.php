<?php

namespace Innoboxrr\LarapackGenerator\Tools\Model;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class ModelTool extends Tool
{
    protected string $modelPath = '';

    protected string $modelTemplatePath = '';

    private function setModelPath(): static
    {
        $this->modelPath = get_path(app_dir_name().'/Models');

        return $this;
    }

    private function setModelTemplatePath(): static
    {
        $this->modelTemplatePath = stubs_path('Model');

        return $this;
    }

    public function create(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelPath()
            ->setModelTemplatePath();

        $modelFile = $this->modelPath.'/'.$this->PascalCaseModelName.'.php';

        return $this->generate($this->modelTemplatePath.'/ModelTemplate.txt', $modelFile);
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelPath();
        $path = $this->modelPath.'/'.$this->PascalCaseModelName.'.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;
    }

    protected function processFileWithJson(string $modelFile): void
    {
        // Recuperar la información del modelo
        $model = $this->jsonModel();

        // Recuperar el contenido del archivo del modelo
        $fileContent = file_get_contents($modelFile);

        // Obtener los valores para los diferentes secciones de la plantilla
        $authenticatable = ! empty($model['authenticatable']);

        $fillable = $this->generateListFromProps($model['props'], 'fillable');
        $hidden = $this->generateHidden($model['props'], $authenticatable);
        $creatable = $this->generateListFromProps($model['props'], 'creatable');
        $updatable = $this->generateListFromProps($model['props'], 'updatable');
        $casts = $this->generateCasts($model['props'], $authenticatable);
        $editableMetas = $this->generateEditableMetas($model['editable_metas']);
        $exportCols = $this->generateExportCols($model['props']);
        $loadableRelations = $this->generateLoadableRelations($model['load_relations']);
        $loadableCounts = $this->generateLoadableCounts($model['load_counts']);

        // Reemplazar los marcadores en el archivo del modelo
        $updatedFileContent = str_replace('//FILLABLE//', $fillable, $fileContent);
        $updatedFileContent = str_replace('//HIDDEN//', $hidden, $updatedFileContent);
        $updatedFileContent = str_replace('//CREATABLE//', $creatable, $updatedFileContent);
        $updatedFileContent = str_replace('//UPDATABLE//', $updatable, $updatedFileContent);
        $updatedFileContent = str_replace('//CASTS//', $casts, $updatedFileContent);
        $updatedFileContent = str_replace('//EDITABLEMETAS//', $editableMetas, $updatedFileContent);

        // Sólo si hay alguna: sin ellas, la línea sale como siempre.
        if (! empty($model['protected_metas'])) {
            $updatedFileContent = str_replace(
                'protected $protected_metas = [];',
                "protected \$protected_metas = [\n        ".$this->generateEditableMetas($model['protected_metas'])."\n    ];",
                $updatedFileContent
            );
        }
        $updatedFileContent = str_replace('//EXPORTCOLS//', $exportCols, $updatedFileContent);
        $updatedFileContent = str_replace('//LOADABLERELATIONS//', $loadableRelations, $updatedFileContent);
        $updatedFileContent = str_replace('//LOADABLECOUNTS//', $loadableCounts, $updatedFileContent);

        // Guardar el archivo de nuevo con las modificaciones
        file_put_contents($modelFile, $updatedFileContent);
    }

    /**
     * @param  list<array<string, mixed>>  $props
     */
    private function generateListFromProps(array $props, string $field): string
    {
        $list = [];

        foreach ($props as $prop) {
            if ($prop[$field]) {
                $list[] = "'{$prop['name']}'";
            }
        }

        return implode(', ', $list);
    }

    /**
     * Las columnas que nunca salen por la API: las secretas y, en un usuario
     * que inicia sesión, la contraseña y el token de "recordarme" aunque no se
     * declaren.
     *
     * @param  list<array<string, mixed>>  $props
     */
    private function generateHidden(array $props, bool $authenticatable): string
    {
        $hidden = array_column(array_filter($props, fn (array $prop): bool => ! empty($prop['secret'])), 'name');

        if ($authenticatable) {
            $hidden = [...$hidden, 'password', 'remember_token'];
        }

        return implode(', ', array_map(fn (string $name): string => "'{$name}'", array_values(array_unique($hidden))));
    }

    /**
     * Los casts declarados y, en un usuario que inicia sesión, los que Laravel
     * espera: la fecha de verificación y la contraseña cifrada al asignarla.
     *
     * @param  list<array<string, mixed>>  $props
     */
    private function generateCasts(array $props, bool $authenticatable = false): string
    {
        $casts = [];

        foreach ($props as $prop) {
            if (! is_null($prop['cast'])) {
                $casts[$prop['name']] = "'{$prop['name']}' => ".self::phpString((string) $prop['cast']);
            }
        }

        if ($authenticatable) {
            $names = array_column($props, 'name');

            foreach (['email_verified_at' => 'datetime', 'password' => 'hashed'] as $name => $cast) {
                if (in_array($name, $names, true) && ! isset($casts[$name])) {
                    $casts[$name] = "'{$name}' => '{$cast}'";
                }
            }
        }

        return implode(', ', $casts);
    }

    /**
     * @param  list<string>  $editableMetas
     */
    private function generateEditableMetas(array $editableMetas): string
    {
        if (empty($editableMetas)) {
            return '';
        }

        return implode(', ', array_map(fn ($meta) => "'{$meta}'", $editableMetas));
    }

    /**
     * @param  list<array<string, mixed>>  $props
     */
    private function generateExportCols(array $props): string
    {
        $exportCols = [];

        foreach ($props as $prop) {
            if ($prop['exports_cols']) {
                $exportCols[] = "'{$prop['name']}'";
            }
        }

        return implode(', ', $exportCols);
    }

    /**
     * @param  list<array<string, mixed>>  $loadRelations
     */
    private function generateLoadableRelations(array $loadRelations): string
    {
        if (empty($loadRelations)) {
            return '';
        }

        return implode(', ', array_map(fn ($relation) => "'{$relation['name']}'", $loadRelations));
    }

    /**
     * @param  list<string>  $loadCounts
     */
    private function generateLoadableCounts(array $loadCounts): string
    {
        if (empty($loadCounts)) {
            return '';
        }

        return implode(', ', array_map(fn ($count) => "'{$count}'", $loadCounts));
    }
}
