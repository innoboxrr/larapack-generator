<?php

namespace Innoboxrr\LarapackGenerator\Tools\Factory;

use Illuminate\Support\Pluralizer;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class FactoryTool extends Tool
{
    protected $factoryPath;

    protected $factoryTemplatePath;

    private function setFactoryPath()
    {
        $this->factoryPath = get_path('database/factories');

        return $this;
    }

    private function setFactoryTemplatePath()
    {
        $this->factoryTemplatePath = stubs_path('Factory');

        return $this;
    }

    public function create(string $ModelName)
    {
        $this->init($ModelName)
            ->setFactoryPath()
            ->setFactoryTemplatePath()
            ->addDatabaseNamespaceToComposerJson();

        $factoryFile = $this->factoryPath.'/'.$this->PascalCaseModelName.'Factory.php';

        return $this->generate($this->factoryTemplatePath.'/FactoryTemplate.txt', $factoryFile);
    }

    private function addDatabaseNamespaceToComposerJson()
    {
        // Una simulación no escribe nada, tampoco composer.json.
        if (app_dir_name() == 'src' && ! Generation::isDryRun()) {
            $composerJsonPath = root_path().'/composer.json';
            $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
            $baseNamespace = array_keys($composerJsonData['autoload']['psr-4'])[0];
            if (isset($composerJsonData['autoload']['psr-4'])) {
                $composerJsonData['autoload']['psr-4'][$baseNamespace.'Database\\Factories\\'] = 'database/factories/';
            } else {
                $composerJsonData['autoload'] = [
                    'psr-4' => [
                        $baseNamespace.'Database\\Factories\\' => 'database/factories/',
                    ],
                ];
            }
            file_put_contents(
                $composerJsonPath,
                json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        return $this;
    }

    public function remove(string $ModelName)
    {
        $this->init($ModelName)
            ->setFactoryPath();
        $path = $this->factoryPath.'/'.$this->PascalCaseModelName.'Factory.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;
    }

    protected function processFileWithJson($factoryFile)
    {
        $data = self::getJsonContent();
        $model = collect($data['models'])->where('name', $this->ModelName)->first();
        $fileContent = file_get_contents($factoryFile);
        // `payload` lo arma updatePayload() a partir de las metas: una factory
        // que lo inventara guardaría una copia que no corresponde a nada.
        $props = empty($model['metas'])
            ? $model['props']
            : array_values(array_filter($model['props'], fn (array $prop): bool => $prop['name'] !== 'payload'));

        $definitionContent = $this->generateFactoryDefinition($props);
        $updatedFileContent = str_replace('//EDIT//', $definitionContent, $fileContent);
        file_put_contents($factoryFile, $updatedFileContent);
    }

    private function generateFactoryDefinition(array $props)
    {
        $lines = [];

        foreach ($props as $prop) {
            $value = $this->fakerValue($prop);

            if ($value !== null) {
                $lines[] = "'{$prop['name']}' => {$value},";
            }
        }

        // El marcador ya lleva la sangria de la primera linea.
        return implode("\n            ", $lines);
    }

    /**
     * Un valor que la columna acepta, como expresion PHP; null si la propiedad
     * no es una columna con nombre propio.
     *
     * Antes solo string, longText, integer y foreignId tenian valor: el resto
     * salia null, y un decimal o un booleano no anulables hacian fallar el
     * insert. La clave foranea valia siempre 1, una fila que nadie garantizaba.
     * Una factory que no inserta rompe los tests generados y cualquier seeder.
     */
    private function fakerValue(array $prop): ?string
    {
        if (! empty($prop['enum']) && is_array($prop['enum'])) {
            $options = array_map(fn ($value) => var_export((string) $value, true), array_keys($prop['enum']));

            return '$this->faker->randomElement(['.implode(', ', $options).'])';
        }

        switch ($prop['type']) {
            case 'foreignId':
            case 'foreignUuid':
                return $this->foreignKeyValue($prop);
            case 'string':
            case 'char':
            case 'tinyText':
                return '$this->faker->words(3, true)';
            case 'text':
            case 'mediumText':
            case 'longText':
                return '$this->faker->paragraph()';
            case 'integer':
            case 'bigInteger':
            case 'mediumInteger':
            case 'smallInteger':
            case 'tinyInteger':
            case 'unsignedInteger':
            case 'unsignedBigInteger':
            case 'unsignedMediumInteger':
            case 'unsignedSmallInteger':
            case 'unsignedTinyInteger':
                return '$this->faker->numberBetween(0, 100)';
            case 'decimal':
            case 'float':
            case 'double':
                return '$this->faker->randomFloat(2, 0, 1000)';
            case 'boolean':
                return '$this->faker->boolean()';
                // Texto y no DateTime: los tests generados mandan los atributos de la
                // factory como JSON, y un objeto DateTime se serializa como objeto.
            case 'date':
                return '$this->faker->date()';
            case 'dateTime':
            case 'dateTimeTz':
            case 'timestamp':
            case 'timestampTz':
                return "\$this->faker->dateTime()->format('Y-m-d H:i:s')";
            case 'time':
            case 'timeTz':
                return '$this->faker->time()';
            case 'year':
                return '(int) $this->faker->year()';
            case 'json':
            case 'jsonb':
                // Sin cast el modelo no serializa un array: hay que darle el texto.
                return empty($prop['cast']) ? "'[]'" : '[]';
            case 'uuid':
                return '$this->faker->uuid()';
            case 'ipAddress':
                return '$this->faker->ipv4()';
            case 'macAddress':
                return '$this->faker->macAddress()';
            case 'increments':
            case 'bigIncrements':
            case 'morphs':
            case 'nullableMorphs':
                // La clave primaria la pone la base, y morphs no es una columna
                // con el nombre de la propiedad sino dos (_id y _type).
                return null;
            default:
                return 'null';
        }
    }

    /**
     * La factory del modelo al que apunta la clave, si se declara en el mismo
     * laraimport: Laravel crea la fila relacionada antes de insertar esta.
     */
    private function foreignKeyValue(array $prop): string
    {
        foreach (self::getJsonContent()['models'] ?? [] as $model) {
            $table = Pluralizer::plural(mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $model['name'])));

            if ($table !== $prop['constraint']) {
                continue;
            }

            // Hacia si mismo la clave es anulable (el validador lo exige), y
            // una factory que se llama a si misma no termina.
            if ($model['name'] === $this->ModelName) {
                return 'null';
            }

            return '\\'.$this->namespace.'Models\\'.$model['name'].'::factory()';
        }

        // Una tabla que este paquete no declara —users, la de otro paquete— no
        // tiene una factory que se conozca desde aqui.
        return ! empty($prop['nullable']) ? 'null' : '1';
    }
}
