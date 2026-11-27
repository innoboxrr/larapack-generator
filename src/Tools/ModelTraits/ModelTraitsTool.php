<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelTraits;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ModelTraitsTool extends Tool implements ModelArtifact
{
    protected string $modelTraitsPath = '';

    protected string $modelTraitsTemplatePath = '';

    protected string $assignmentsPath = '';

    protected string $mutatorsPath = '';

    protected string $operationsPath = '';

    protected string $queriesPath = '';

    protected string $relationsPath = '';

    protected string $storagePath = '';

    private function setModelTraitsPath(): static
    {
        $this->modelTraitsPath = get_path(app_dir_name().'/Models/Traits');

        return $this;
    }

    private function setModelTraitsTemplatePath(): static
    {
        $this->modelTraitsTemplatePath = stubs_path('ModelTraits');

        return $this;
    }

    protected function setTraitsPath(): static
    {
        $this->assignmentsPath = $this->modelTraitsPath.'/Assignments';
        if (! file_exists($this->assignmentsPath)) {
            mkdir($this->assignmentsPath, 0777, true);
        }
        $this->mutatorsPath = $this->modelTraitsPath.'/Mutators';
        if (! file_exists($this->mutatorsPath)) {
            mkdir($this->mutatorsPath, 0777, true);
        }
        $this->operationsPath = $this->modelTraitsPath.'/Operations';
        if (! file_exists($this->operationsPath)) {
            mkdir($this->operationsPath, 0777, true);
        }
        $this->relationsPath = $this->modelTraitsPath.'/Relations';
        if (! file_exists($this->relationsPath)) {
            mkdir($this->relationsPath, 0777, true);
        }
        $this->storagePath = $this->modelTraitsPath.'/Storage';
        if (! file_exists($this->storagePath)) {
            mkdir($this->storagePath, 0777, true);
        }

        return $this;
    }

    /**
     * Los traits se copian sin pasar por generate(), así que la simulación se
     * respeta aquí: se anota lo que se crearía y no se escribe. Antes
     * `larapack:import --dry-run` los creaba igualmente.
     */
    private function simulated(string $template, string $file): bool
    {
        if (! Generation::isDryRun()) {
            return false;
        }

        Generation::record('create', $file, $template);

        return true;
    }

    public function create(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelTraitsPath()
            ->setModelTraitsTemplatePath()
            ->setTraitsPath()
            ->createTraits();

        return true;
    }

    private function createTraits(): static
    {
        $this->createAssignmentTrait()
            ->createMutatorsTrait()
            ->createOperationsTrait()
            ->createRelationsTrait()
            ->createStorageTrait();

        return $this;
    }

    private function createAssignmentTrait(): static
    {
        $assignmentTraitFile = $this->modelTraitsPath.'/Assignments/'.$this->PascalCaseModelName.'Assignment.php';
        if (! file_exists($assignmentTraitFile)) {
            $templateFile = $this->modelTraitsTemplatePath.'/Assignments/AssignmentTemplate.txt';
            if ($this->simulated($templateFile, $assignmentTraitFile)) {
                return $this;
            }
            if (copy($templateFile, $assignmentTraitFile)) {
                $this->replaceData($assignmentTraitFile);
            } else {
                throw MakerException::copyFailed($templateFile, $assignmentTraitFile);
            }
        }

        return $this;
    }

    private function createMutatorsTrait(): static
    {
        $mutatorsTraitFile = $this->modelTraitsPath.'/Mutators/'.$this->PascalCaseModelName.'Mutators.php';
        if (! file_exists($mutatorsTraitFile)) {
            $templateFile = $this->modelTraitsTemplatePath.'/Mutators/MutatorsTemplate.txt';
            if ($this->simulated($templateFile, $mutatorsTraitFile)) {
                return $this;
            }
            if (copy($templateFile, $mutatorsTraitFile)) {
                $this->replaceData($mutatorsTraitFile);
            } else {
                throw MakerException::copyFailed($templateFile, $mutatorsTraitFile);
            }
        }

        return $this;
    }

    private function createOperationsTrait(): static
    {
        $operationsTraitFile = $this->modelTraitsPath.'/Operations/'.$this->PascalCaseModelName.'Operations.php';
        if (! file_exists($operationsTraitFile)) {
            $templateFile = $this->modelTraitsTemplatePath.'/Operations/OperationsTemplate.txt';
            if ($this->simulated($templateFile, $operationsTraitFile)) {
                return $this;
            }
            if (copy($templateFile, $operationsTraitFile)) {
                $this->applyBlocks($operationsTraitFile);
                $this->replaceData($operationsTraitFile);
                $this->applyEndpoints($operationsTraitFile);
            } else {
                throw MakerException::copyFailed($templateFile, $operationsTraitFile);
            }
        }

        return $this;
    }

    /**
     * La operación de cada endpoint, que es donde va su lógica: la request
     * autoriza, valida y delega aquí.
     *
     * Sólo cuando el trait se crea. Uno que ya existe es código del proyecto y
     * el generador no escribe en él; si le falta una operación, verify lo dice
     * con el nombre exacto del método.
     *
     * Nace lanzando una excepción y no devolviendo algo vacío: un endpoint que
     * respondiera 200 sin hacer nada pasaría por implementado.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        if ($marker !== 'ENDPOINT_OPERATIONS') {
            return [];
        }

        $lines = [];

        foreach ($this->endpoints() as $endpoint) {
            $verb = strtoupper($endpoint['method']);
            $subject = $endpoint['target'] === 'record'
                ? ' * La request ya autorizó, validó y buscó el registro: $this es él.'
                : ' * La request ya autorizó y validó. $this es una instancia vacía, como en createModel().';

            array_push(
                $lines,
                '',
                '/**',
                " * {$verb} {$endpoint['uri']}: el endpoint {$endpoint['name']} del laraimport.",
                ' *',
                $subject,
                ' * Lo que devuelvas es la respuesta.',
                ' */',
                "public function {$endpoint['operation']}(\$request)",
                '{',
                "    throw new \\LogicException('{$this->PascalCaseModelName}::{$endpoint['operation']}() no está implementada.');",
                '}'
            );
        }

        return $lines;
    }

    private function createRelationsTrait(): static
    {
        $relationsTraitFile = $this->modelTraitsPath.'/Relations/'.$this->PascalCaseModelName.'Relations.php';
        if (! file_exists($relationsTraitFile)) {
            $templateFile = $this->modelTraitsTemplatePath.'/Relations/RelationsTemplate.txt';
            if ($this->simulated($templateFile, $relationsTraitFile)) {
                return $this;
            }
            if (copy($templateFile, $relationsTraitFile)) {
                // Los traits se copian sin pasar por generate(), así que los
                // bloques de metas se resuelven aquí.
                $this->applyBlocks($relationsTraitFile);
                $this->replaceData($relationsTraitFile);
                if (self::isFromJsonImporter()) {
                    $this->processRelationsTraitWithJson($relationsTraitFile);
                }
            } else {
                throw MakerException::copyFailed($templateFile, $relationsTraitFile);
            }
        }

        return $this;
    }

    private function processRelationsTraitWithJson(string $traitFile): void
    {
        // Encontrar el modelo actual basado en su nombre
        $model = $this->jsonModel();

        // Obtener las relaciones
        $relations = $model['load_relations'] ?? [];

        $relationsCode = '';
        $imports = [];

        // Iterar a través de las relaciones
        foreach ($relations as $relation) {
            $relationType = $relation['type'];   // belongsTo, hasMany, etc.
            $relatedModel = $relation['related']; // Modelo relacionado (por ejemplo, User)
            $relationName = $relation['name'];   // Nombre de la relación (por ejemplo, user)
            // El documento resuelve el namespace: null significa que el modelo
            // relacionado se declara en este mismo laraimport, es decir que vive
            // en el paquete. Antes se asumia siempre App\Models y el `use`
            // generado apuntaba a una clase inexistente.
            $relatedNamespace = rtrim(
                $relation['namespace'] ?? (rtrim($this->namespace, '\\').'\\Models'),
                '\\'
            );

            // Generar el código de la relación
            $relationsCode .= "    public function {$relationName}()\n";
            $relationsCode .= "    {\n";
            $relationsCode .= "        return \$this->{$relationType}({$relatedModel}::class);\n";
            $relationsCode .= "    }\n\n";

            // Agregar el import del modelo relacionado, si aún no está en la lista
            $importStatement = "use {$relatedNamespace}\\{$relatedModel};";

            // Una relacion hacia el propio modelo no necesita import, y en PHP
            // importarse a si mismo es un error de compilacion.
            if ($relatedModel === $this->PascalCaseModelName) {
                continue;
            }

            if (! in_array($importStatement, $imports)) {
                $imports[] = $importStatement;
            }
        }

        // Cargar el contenido del archivo de plantilla
        $fileContent = self::contents($traitFile);

        // Unir los imports generados
        $importCode = implode("\n", $imports);

        // Reemplazar los placeholders con los imports y el código generado
        $fileContent = str_replace('//IMPORTS//', rtrim($importCode, "\n"), $fileContent);
        $fileContent = str_replace('//EDIT//', rtrim($relationsCode, "\n"), $fileContent);

        // Guardar el archivo modificado
        file_put_contents($traitFile, $fileContent);
    }

    private function createStorageTrait(): static
    {
        $storageTraitFile = $this->modelTraitsPath.'/Storage/'.$this->PascalCaseModelName.'Storage.php';
        if (! file_exists($storageTraitFile)) {
            $templateFile = $this->modelTraitsTemplatePath.'/Storage/StorageTemplate.txt';
            if ($this->simulated($templateFile, $storageTraitFile)) {
                return $this;
            }
            if (copy($templateFile, $storageTraitFile)) {
                $this->applyBlocks($storageTraitFile);
                $this->replaceData($storageTraitFile);
            } else {
                throw MakerException::copyFailed($templateFile, $storageTraitFile);
            }
        }

        return $this;
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelTraitsPath()
            ->setModelTraitsTemplatePath()
            ->setTraitsPath()
            ->removeTraits();

        return true;
    }

    private function removeTraits(): static
    {
        $this->removeAssignmentTrait()
            ->removeMutatorsTrait()
            ->removeOperationsTrait()
            ->removeRelationsTrait()
            ->removeStorageTrait();

        return $this;
    }

    private function removeAssignmentTrait(): static
    {
        $path = $this->modelTraitsPath.'/Assignments/'.$this->PascalCaseModelName.'Assignment.php';
        $res = (file_exists($path)) ? $this->dropFile($path) : false;

        return $this;
    }

    private function removeMutatorsTrait(): static
    {
        $path = $this->modelTraitsPath.'/Mutators/'.$this->PascalCaseModelName.'Mutators.php';
        $res = (file_exists($path)) ? $this->dropFile($path) : false;

        return $this;
    }

    private function removeOperationsTrait(): static
    {
        $path = $this->modelTraitsPath.'/Operations/'.$this->PascalCaseModelName.'Operations.php';
        $res = (file_exists($path)) ? $this->dropFile($path) : false;

        return $this;
    }

    private function removeRelationsTrait(): static
    {
        $path = $this->modelTraitsPath.'/Relations/'.$this->PascalCaseModelName.'Relations.php';
        $res = (file_exists($path)) ? $this->dropFile($path) : false;

        return $this;
    }

    private function removeStorageTrait(): static
    {
        $path = $this->modelTraitsPath.'/Storage/'.$this->PascalCaseModelName.'Storage.php';
        $res = (file_exists($path)) ? $this->dropFile($path) : false;

        return $this;
    }
}
