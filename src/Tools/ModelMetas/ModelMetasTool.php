<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelMetas;

use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class ModelMetasTool extends Tool
{
    protected string $modelMetasPath = '';

    protected string $migrationMetasPath = '';

    protected string $modelMetasTemplatePath = '';

    protected string $migrationMetasTemplatePath = '';

    private function setModelMetasPath(): static
    {
        $this->modelMetasPath = get_path(app_dir_name().'/Models');

        return $this;
    }

    private function setMigrationMetasPath(): static
    {
        $this->migrationMetasPath = get_path('database/migrations');

        return $this;
    }

    private function setModelMetasTemplatePath(): static
    {
        $this->modelMetasTemplatePath = stubs_path('ModelMetas');

        return $this;
    }

    private function setMigrationMetasTemplatePath(): static
    {
        $this->migrationMetasTemplatePath = stubs_path('MigrationMetas');

        return $this;
    }

    public function create(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelMetasPath()
            ->setMigrationMetasPath()
            ->setModelMetasTemplatePath()
            ->setMigrationMetasTemplatePath();

        $this->createModelMetas();
        $this->createMigrationMetas();

        return true;
    }

    /**
     * Por generate(), como el resto: antes se copiaba a mano, así que no se
     * anotaba en el manifiesto, ignoraba --dry-run y --force, y no se protegía
     * si se había editado.
     */
    private function createModelMetas(): bool
    {
        return $this->generate(
            $this->modelMetasTemplatePath.'/ModelMetasTemplate.txt',
            $this->modelMetasPath.'/'.$this->PascalCaseModelName.'Meta.php'
        );
    }

    private function createMigrationMetas(): bool
    {
        date_default_timezone_set('UTC');
        // Añade 3 segundos a la fecha actual
        // La migración de metas tiene que correr después de la del modelo,
        // que ya se pidió antes: el contador lo garantiza sin sumar segundos.
        $migrationMetasFile = $this->migrationFile($this->migrationMetasPath, 'create_'.$this->metasTableName().'_table');

        return $this->generate($this->migrationMetasTemplatePath.'/MigrationTemplate.txt', $migrationMetasFile);
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setModelMetasPath()
            ->setMigrationMetasPath()
            // Faltaba: la migración de borrado buscaba su plantilla en una ruta
            // vacía y quitar un modelo con metas lanzaba "stub no encontrado".
            ->setMigrationMetasTemplatePath();

        // Un modelo que nunca tuvo metas no tiene tabla que borrar: sin esta
        // comprobación, quitarlo dejaba una migración que borra una tabla que
        // no existe.
        if (! file_exists($this->metaModelFile())) {
            return false;
        }

        // Eliminar el modelo y crear la migración de eliminación
        $this->removeModelMetas();
        $this->removeMigrationMetas();

        return true;
    }

    private function metaModelFile(): string
    {
        return $this->modelMetasPath.'/'.$this->PascalCaseModelName.'Meta.php';
    }

    /**
     * Se buscaba `<Modelo>ModelMetas.php`, pero el archivo que se crea es
     * `<Modelo>Meta.php`: el modelo Meta no se borraba nunca.
     */
    private function removeModelMetas(): bool
    {
        $path = $this->metaModelFile();

        return (file_exists($path)) ? $this->dropFile($path) : false;
    }

    private function removeMigrationMetas(): bool
    {
        date_default_timezone_set('UTC');
        $migrationFilename = MigrationTimestamp::next().'_drop_'.$this->metasTableName().'_table.php';
        $migrationFile = $this->migrationMetasPath.'/'.$migrationFilename;

        return $this->generate($this->migrationMetasTemplatePath.'/DropTemplate.txt', $migrationFile);
    }
}
