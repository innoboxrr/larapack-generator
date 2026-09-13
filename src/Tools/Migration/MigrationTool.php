<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class MigrationTool extends Tool
{
    /**
     * Una columna tal como la escribe el generador: `$table->tipo('nombre')`
     * con sus modificadores, en una línea.
     */
    private const COLUMN = '/^\s*(\$table->(\w+)\(\'([A-Za-z0-9_]+)\'\).*;)\s*$/m';

    protected $migrationPath;

    protected $migrationTemplatePath;

    private function setMigrationPath()
    {
        $this->migrationPath = get_path('database/migrations');

        return $this;
    }

    private function setMigrationTemplatePath()
    {
        $this->migrationTemplatePath = stubs_path('Migration');

        return $this;
    }

    public function create(string $ModelName)
    {
        $this->init($ModelName)
            ->setMigrationPath()
            ->setMigrationTemplatePath();

        $migrationFile = $this->migrationFile($this->migrationPath, 'create_'.$this->plural_snake_case_model_name.'_table');

        // Una tabla que ya existe no cambia reescribiendo su migración de
        // creación: quien ya la migró no la vuelve a crear. Lo que cambió en
        // el laraimport va en una migración de alteración.
        if (self::isFromJsonImporter() && is_file($migrationFile)) {
            $altered = $this->alter($migrationFile);

            if ($altered !== null) {
                return $altered;
            }
        }

        return $this->generate($this->migrationTemplatePath.'/MigrationTemplate.txt', $migrationFile);
    }

    /**
     * Escribe la migración con lo que añade, cambia o quita el laraimport
     * respecto de lo que ya dejaron las migraciones de la tabla.
     *
     * @return bool|null null si el esquema no cambió
     */
    private function alter(string $createFile): ?bool
    {
        $model = collect(self::getJsonContent()['models'])->where('name', $this->ModelName)->first();

        $desired = [];

        foreach ($model['props'] as $prop) {
            $desired[$prop['name']] = $this->getColumnDefinition($prop);
        }

        $current = $this->currentColumns($createFile);

        $added = array_diff_key($desired, $current);
        $dropped = array_diff_key($current, $desired);
        $changed = array_filter(
            array_intersect_key($desired, $current),
            fn (string $definition, string $name): bool => $definition !== $current[$name],
            ARRAY_FILTER_USE_BOTH
        );

        if ($added === [] && $dropped === [] && $changed === []) {
            return null;
        }

        $stub = $this->migrationTemplatePath.'/AlterTemplate.txt';

        // Lo que alguien escribió a mano en la migración —un índice, una
        // columna fuera del laraimport— no se puede leer con seguridad, y
        // adivinar aquí acaba en un dropColumn de algo que existe.
        if ($this->manifest()->wasCustomised($createFile)) {
            Generation::record('preserved', $createFile, $stub, 'el esquema cambió y la migración está editada a mano: escribe la alteración');

            return false;
        }

        $destination = $this->migrationPath.'/'.$this->alterTimestamp($createFile).'_alter_'.$this->plural_snake_case_model_name.'_table.php';

        if (Generation::isDryRun()) {
            Generation::record('create', $destination, $stub);

            return true;
        }

        $up = [];
        $down = [];

        foreach ($added as $name => $definition) {
            $up[] = $definition;
            $down[] = $this->dropStatement($name, $definition);
        }

        foreach ($changed as $name => $definition) {
            // Cambiar una llave foránea es quitarla y volver a crearla, con
            // sus datos: eso no se decide solo.
            if ($this->isForeign($definition) || $this->isForeign($current[$name])) {
                $up[] = "// {$name} cambió y es una llave foránea: escribe el cambio a mano.";

                continue;
            }

            $up[] = substr($definition, 0, -1).'->change();';
            $down[] = substr($current[$name], 0, -1).'->change();';
        }

        foreach ($dropped as $name => $definition) {
            $up[] = $this->dropStatement($name, $definition);
            $down[] = $definition;
        }

        if (! copy($stub, $destination)) {
            throw MakerException::copyFailed($stub, $destination);
        }

        $this->replaceData($destination);

        file_put_contents($destination, strtr((string) file_get_contents($destination), [
            '//UP//' => implode("\n            ", $up),
            '//DOWN//' => implode("\n            ", array_reverse($down)),
        ]));

        $this->manifest()->record($this->ModelName, $this->namespace, $destination, $stub);

        Generation::record('create', $destination, $stub);

        return true;
    }

    /**
     * Las columnas que tiene hoy la tabla según sus migraciones: la de
     * creación y, en orden, las alteraciones escritas después.
     *
     * @return array<string, string> nombre => definición
     */
    private function currentColumns(string $createFile): array
    {
        $columns = [];

        preg_match_all(self::COLUMN, (string) file_get_contents($createFile), $matches, PREG_SET_ORDER);

        foreach ($matches as [, $statement, , $name]) {
            $columns[$name] = $statement;
        }

        foreach ($this->alterFiles() as $alter) {
            $source = (string) file_get_contents($alter);
            $up = substr($source, 0, strpos($source, 'function down') ?: strlen($source));

            preg_match_all(self::COLUMN, $up, $lines, PREG_SET_ORDER);

            foreach ($lines as [, $statement, $method, $name]) {
                if (in_array($method, ['dropColumn', 'dropConstrainedForeignId'], true)) {
                    unset($columns[$name]);

                    continue;
                }

                $columns[$name] = str_ends_with($statement, '->change();')
                    ? substr($statement, 0, -strlen('->change();')).';'
                    : $statement;
            }
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    private function alterFiles(): array
    {
        $files = glob($this->migrationPath.'/*_alter_'.$this->plural_snake_case_model_name.'_table.php') ?: [];

        sort($files);

        return $files;
    }

    /**
     * Después de la última migración de la tabla. Con el sello del reloj, una
     * alteración escrita en el mismo segundo que la creación se ordenaba antes
     * que ella —`alter` va antes que `create`— y migrate fallaba.
     */
    private function alterTimestamp(string $createFile): string
    {
        $next = MigrationTimestamp::next();

        $latest = max(array_map(
            fn (string $file): string => substr(basename($file), 0, 17),
            [$createFile, ...$this->alterFiles()]
        ));

        if ($next > $latest) {
            return $next;
        }

        $moment = \DateTimeImmutable::createFromFormat('Y_m_d_His', $latest, new \DateTimeZone('UTC'));

        return $moment->modify('+1 second')->format('Y_m_d_His');
    }

    private function dropStatement(string $name, string $definition): string
    {
        return $this->isForeign($definition)
            ? "\$table->dropConstrainedForeignId('{$name}');"
            : "\$table->dropColumn('{$name}');";
    }

    private function isForeign(string $definition): bool
    {
        return str_contains($definition, '->constrained(');
    }

    public function remove(string $ModelName)
    {
        $this->init($ModelName)
            ->setMigrationPath()
            ->setMigrationTemplatePath();
        $migrationFilename = MigrationTimestamp::next().'_drop_'.$this->plural_snake_case_model_name.'_table.php';
        $migrationFile = $this->migrationPath.'/'.$migrationFilename;
        // Solo proceder en caso de los archivos no existan
        if (! file_exists($migrationFile)) {
            $templateFile = $this->migrationTemplatePath.'/DropTemplate.txt';
            if (copy($templateFile, $migrationFile)) {
                // Remplace dummy data
                $this->replaceData($migrationFile);
            } else {
                throw MakerException::copyFailed($templateFile, $migrationFile);
            }
        } else {
            return false;
        }

        return true;
    }

    protected function processFileWithJson($migrationFile)
    {
        $data = self::getJsonContent();
        $model = collect($data['models'])->where('name', $this->ModelName)->first();
        $fileContent = file_get_contents($migrationFile);
        $columnsSchema = $this->generateMigrationColumns($model['props']);
        $updatedFileContent = str_replace('//EDIT//', $columnsSchema, $fileContent);
        file_put_contents($migrationFile, $updatedFileContent);
    }

    private function generateMigrationColumns(array $props)
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

    private function getColumnDefinition(array $prop)
    {
        $column = "\$table->{$prop['type']}('{$prop['name']}')";

        if ($prop['nullable']) {
            $column .= '->nullable()';
        }

        if (array_key_exists('default', $prop) && $prop['default'] !== null) {

            $default = $prop['default'];

            // Determinar tipo de valor default
            if (is_bool($default)) {
                $default = $default ? 'true' : 'false';
            } elseif (is_numeric($default)) {
                // se deja tal cual
            } else {
                $default = "'{$default}'"; // string u otro tipo
            }

            $column .= "->default({$default})";
        }

        if ($prop['type'] === 'foreignId' && ! is_null($prop['constraint'])) {
            $column .= "->constrained('{$prop['constraint']}')->onUpdate('cascade')->onDelete('cascade')";
        }

        return $column.';';
    }
}
