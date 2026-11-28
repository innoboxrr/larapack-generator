<?php

namespace Innoboxrr\LarapackGenerator\Tools\Model;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\ModelLists;
use Innoboxrr\LarapackGenerator\Support\ModelRegions;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ModelTool extends Tool implements ModelArtifact
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

    /**
     * Un modelo editado a mano se conserva, pero sus listas son del
     * generador: se completan con lo que el laraimport declara y falta
     * (ModelRegions), sin tocar nada más. Sólo desde el laraimport: sin él no
     * hay contrato contra el que completar.
     */
    protected function preserve(string $destination): ?string
    {
        if (! self::isFromJsonImporter()) {
            return null;
        }

        $relative = str_starts_with($destination, root_path().'/') ? substr($destination, strlen(root_path()) + 1) : $destination;
        $result = ModelRegions::sync(self::contents($destination), ModelLists::expected(self::declaredEntry('models', $this->ModelName)), $relative);

        if ($result['added'] === [] && $result['skipped'] === []) {
            return null;
        }

        if ($result['added'] !== [] && ! Generation::isDryRun()) {
            Files::write($destination, $result['code']);
        }

        Generation::regions($destination, $result['added'], $result['skipped']);

        $regions = array_map(
            fn (string $region): string => $region === 'casts' ? 'casts()' : '$'.$region,
            array_keys($result['added'])
        );

        return 'editado a mano'
            .($regions === [] ? '' : '; '.(Generation::isDryRun() ? 'se completarían ' : 'completado ').implode(', ', $regions))
            .($result['skipped'] === [] ? '' : '; '.count($result['skipped']).' región(es) sin reconocer: complétalas a mano');
    }

    protected function processFileWithJson(string $modelFile): void
    {
        // Recuperar la información del modelo
        $model = self::declaredEntry('models', $this->ModelName);

        // Recuperar el contenido del archivo del modelo
        $fileContent = self::contents($modelFile);

        // Las listas salen de ModelLists: es lo mismo que comprueba `verify`
        // y que se completa en un modelo editado a mano.
        $lists = ModelLists::expected($model);

        $fillable = ModelLists::render($lists['fillable']);
        $hidden = ModelLists::render($lists['hidden']);
        $creatable = ModelLists::render($lists['creatable']);
        $updatable = ModelLists::render($lists['updatable']);
        $casts = ModelLists::renderCasts($lists['casts']);
        $editableMetas = $this->generateEditableMetas($model['editable_metas']);
        $exportCols = ModelLists::render($lists['export_cols']);
        $loadableRelations = ModelLists::render($lists['loadable_relations']);
        $loadableCounts = ModelLists::render($lists['loadable_counts']);

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
        Files::write($modelFile, $updatedFileContent);
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
}
