<?php

namespace Innoboxrr\LarapackGenerator\Tools\PivotMigration;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class PivotMigrationTool extends Tool
{
    protected string $migrationPath = '';

    protected string $migrationTemplatePath = '';

    protected string $migrationName = '';

    private function setMigrationPath(): static
    {
        $this->migrationPath = get_path('database/migrations');

        return $this;
    }

    private function setPivotMigrationTemplatePath(): static
    {
        $this->migrationTemplatePath = stubs_path('PivotMigration');

        return $this;
    }

    public function create(string $migrationName): bool
    {
        $this->migrationName = $migrationName;
        $this->setMigrationPath()
            ->setPivotMigrationTemplatePath();
        // La que ya exista para esta tabla: con la hora en el nombre, importar
        // otra vez creaba una segunda migración de la misma tabla.
        $migrationFile = $this->migrationFile($this->migrationPath, 'create_'.TablePrefix::table($migrationName).'_table');

        // La tabla ya la crea otra migración, con otro nombre (un pivote escrito
        // a mano y adoptado con `realign infer`, 9.4.0): una segunda creación
        // fallaría con «table already exists». Es la misma regla que las
        // migraciones de modelo.
        $state = (new SchemaHistory($this->migrationPath))->table(TablePrefix::table($migrationName));

        if (! file_exists($migrationFile) && $state->exists && $state->creator !== null) {
            Generation::record('skipped', $state->creator, $this->migrationTemplatePath.'/MigrationTemplate.txt', 'la tabla '.TablePrefix::table($migrationName).' ya la crea esta migración, que no generó LaraPack: el esquema lo decide ella');

            return false;
        }

        if (! file_exists($migrationFile)) {
            $templateFile = $this->migrationTemplatePath.'/MigrationTemplate.txt';
            if (Generation::isDryRun()) {
                Generation::record('create', $migrationFile, $templateFile);

                return true;
            }
            if (copy($templateFile, $migrationFile)) {
                $this->replaceMigrationData($migrationFile);
                if (self::isFromJsonImporter()) {
                    $this->processFileWithJson($migrationFile);
                }
                Generation::record('create', $migrationFile, $templateFile);
            } else {
                throw MakerException::copyFailed($templateFile, $migrationFile);
            }
        } else {
            return false;
        }

        return true;
    }

    public function remove(string $migrationName): bool
    {
        $this->migrationName = $migrationName;
        // Asegurarte de que la zona horaria sea la correcta
        date_default_timezone_set('UTC');
        $this->setMigrationPath()
            ->setPivotMigrationTemplatePath();
        $migrationFilename = MigrationTimestamp::next().'_drop_'.TablePrefix::table($migrationName).'_table.php';
        $migrationFile = $this->migrationPath.'/'.$migrationFilename;
        // Solo proceder en caso de los archivos no existan
        if (! file_exists($migrationFile)) {
            $templateFile = $this->migrationTemplatePath.'/DropTemplate.txt';
            if (copy($templateFile, $migrationFile)) {
                $this->replaceMigrationData($migrationFile);
            } else {
                throw MakerException::copyFailed($templateFile, $migrationFile);
            }
        } else {
            return false;
        }

        return true;
    }

    private function replaceMigrationData(string $migrationFile): void
    {
        $content = self::contents($migrationFile);
        $content = str_replace('migration_name', TablePrefix::table($this->migrationName), $content);
        file_put_contents($migrationFile, $content);
    }

    protected function processFileWithJson(string $migrationFile): void
    {
        $pivot = self::declaredEntry('pivots', $this->migrationName);
        $fileContent = self::contents($migrationFile);
        $columnsSchema = $this->generateMigrationColumns($pivot['props']);
        $updatedFileContent = str_replace('//EDIT//', $columnsSchema, $fileContent);
        file_put_contents($migrationFile, $updatedFileContent);
    }

    /**
     * @param  list<array<string, mixed>>  $props
     */
    private function generateMigrationColumns(array $props): string
    {
        $columns = '';
        foreach ($props as $index => $prop) {
            $columnDefinition = $this->getColumnDefinition($prop);
            if ($index == 0) {
                $columns .= "{$columnDefinition}\n";
            } elseif ($index == count($props) - 1) {
                $columns .= "            {$columnDefinition}";
            } else {
                $columns .= "            {$columnDefinition}\n";
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $prop
     */
    private function getColumnDefinition(array $prop): string
    {
        $column = "\$table->{$prop['type']}('{$prop['name']}')";

        // Agregar las propiedades adicionales como 'nullable', 'default', 'after', etc.
        if ($prop['nullable']) {
            $column .= '->nullable()';
        }

        if (! is_null($prop['default'])) {
            // El mismo literal que en las migraciones de modelo: un booleano
            // era '1' o '' —este último, un default inválido para un boolean
            // en MySQL estricto— y un número, una cadena.
            $column .= '->default('.self::defaultLiteral($prop['default'], $prop['type']).')';
        }

        /*
        if (!is_null($prop['after'])) {
            $column .= "->after('{$prop['after']}')";
        }
        */

        // Agregar restricciones de clave foránea si las hay
        if ($prop['type'] === 'foreignId' && ! is_null($prop['constraint'])) {
            // Igual que en las migraciones de modelo: sin prefijo en el
            // contrato, y resuelto aqui segun sea tabla propia o ajena.
            $table = TablePrefix::constraint($prop['constraint']);

            // Y en cascada por omision, que aqui SI es lo correcto: una fila
            // pivote no significa nada sin sus dos lados. Es la unica excepcion
            // al `restrict` por omision de los modelos, y la declara el esquema.
            $column .= '->constrained('.self::phpString($table).')'.$this->referentialActions($prop + [
                'on_update' => 'cascade',
                'on_delete' => 'cascade',
            ]);
        }

        return $column.';';
    }
}
