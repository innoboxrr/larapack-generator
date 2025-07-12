Tengo un JSON para la creación declarativa de diferentes partes del código de una App en Laravel

{
    "models": [
        {
            "name": "Post",
            "props": [
                {
                    "name": "title",
                    "type": "string",
                    "constraint": null,
                    "default": null,
                    "nullable": false,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": "string",
                    "form": true,
                    "form_component": "TextInputComponent",
                    "form_submit": true,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                },
                {
                    "name": "payload",
                    "type": "longText",
                    "constraint": null,
                    "default": null,
                    "nullable": true,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": "json",
                    "form": false,
                    "form_component": null,
                    "form_submit": false,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                },
                {
                    "name": "user_id",
                    "type": "foreignId",
                    "constraint": "users",
                    "default": null,
                    "nullable": false,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": null,
                    "form": false,
                    "form_component": null,
                    "form_submit": true,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                }
            ],
            "metas": true,
            "load_relations": [
                {
                    "type": "belongsTo",
                    "namespace": "App\\Models",
                    "related": "User",
                    "name": "user"
                }
            ],
            "editable_metas": [],
            "assignments": [],
            "filters": [
                {
                    "name": "Title",
                    "mode": "like"
                }
            ],
            "requests": [
                {
                    "name": "Create",
                    "rules": {
                        "title": "required|string|max:255",
                        "payload": "nullable",
                        "user_id": "required|exists:users,id"
                    }
                },
                {
                    "name": "Update",
                    "rules": {
                        "title": "nullable|string|max:255",
                        "payload": "nullable",
                        "user_id": "nullable|exists:users,id"
                    }
                }
            ],
            "load_counts": []
        }
    ],
    "pivots": [
        {
            "name": "role_user",
            "props": [
                {
                    "name": "role_id",
                    "type": "foreignId",
                    "constraint": "roles",
                    "default": null,
                    "nullable": false
                },
                {
                    "name": "user_id",
                    "type": "foreignId",
                    "constraint": "users",
                    "default": null,
                    "nullable": false
                }
            ]
        }
    ]
}


Este es el comando principal

<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Commands\MakeFullModelCommand;
use Innoboxrr\LarapackGenerator\Tools\PivotMigration\PivotMigrationTool;

class JsonImporterCommand extends Command
{
    protected static $defaultName = 'json:importer';

    protected function configure()
    {
        $this->setName('json:importer')
            ->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Include ModelView in commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Obtener la ruta del archivo JSON o usar una predeterminada
        $jsonPath = $input->getArgument('jsonPath') ?? root_path() . '/laraimport.json';

        // Verificar si el archivo existe
        if (!file_exists($jsonPath)) {
            $output->writeln("<error>File not found at {$jsonPath}</error>");
            return Command::FAILURE;
        }

        // Leer y decodificar el contenido JSON
        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        // Validar si el contenido JSON es correcto
        if (is_null($data)) {
            $output->writeln("<error>Invalid JSON content. Null returned</error>");
            return Command::FAILURE;
        }

        // Establecer la variable global `fromJson` en true
        Tool::setFromJsonImporter(true);
        Tool::setJsonContent($data);

        // Procesar cada modelo
        foreach ($data['models'] as $model) {
            $output->writeln("Processing model: {$model['name']}");
            $metas = $model['metas']  === true;
            $this->callMakeFullModelCommand($model['name'], $input->getOption('vue'), $metas, $output);
        }

        // Procesar pivotes (si es necesario)
        foreach ($data['pivots'] as $pivot) {
            sleep(3);
            $output->writeln("Processing pivot: {$pivot['name']}");
            // Aquí puedes implementar el manejo de los pivotes si es necesario
            $tool = new PivotMigrationTool();
            $tool->create($pivot['name']);
        }

        // Limpiar la variable global `fromJson`
        Tool::setFromJsonImporter(false);

        $output->writeln('<info>JSON import completed successfully</info>');
        return Command::SUCCESS;
    }

    private function callMakeFullModelCommand($modelName, $includeVue, $metas, OutputInterface $output)
    {
        // Crear la instancia del comando MakeFullModelCommand
        $command = new MakeFullModelCommand();

        // Crear los argumentos para el comando MakeFullModelCommand
        $arguments = [
            'name' => $modelName
        ];

        // Si la opción --vue está presente, añadirla a los argumentos
        if ($includeVue) {
            $arguments['--vue'] = true;
        }

        // Si la opción --metas está presente, añadirla a los argumentos
        if ($metas) {
            $arguments['--metas'] = true;
        }

        // Crear el input para el comando
        $input = new ArrayInput($arguments);

        // Ejecutar el comando
        $output->writeln("Calling MakeFullModelCommand for model: {$modelName}");

        $resultCode = $command->run($input, $output);

        // Verificar si el comando fue exitoso
        if ($resultCode == 1) {
            $output->writeln("<info>Model {$modelName} created successfully</info>");
        } else {
            $output->writeln("<error>Model {$modelName} could not be created</error>");
        }
    }
}


Se llama en estos lugares

<?php

namespace Innoboxrr\LarapackGenerator\Tools\Factory;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

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

		$factoryFile = $this->factoryPath . '/' . $this->PascalCaseModelName . 'Factory.php';
		if(!file_exists($factoryFile)) {
			$templateFile = $this->factoryTemplatePath . '/FactoryTemplate.txt';
			if(copy($templateFile, $factoryFile)) {
				$this->replaceData($factoryFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($factoryFile);
				}
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	private function addDatabaseNamespaceToComposerJson()
	{
		if(app_dir_name() == 'src') {
			$composerJsonPath = root_path() . '/composer.json';
		    $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
			$baseNamespace = array_keys($composerJsonData['autoload']['psr-4'])[0];
			if (isset($composerJsonData['autoload']['psr-4'])) {
			    $composerJsonData['autoload']['psr-4'][$baseNamespace . 'Database\\Factories\\'] = 'database/factories/';
			} else {
			    $composerJsonData['autoload'] = [
			        'psr-4' => [
			            $baseNamespace . 'Database\\Factories\\' => 'database/factories/',
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
		$path = $this->factoryPath . '/' . $this->PascalCaseModelName . 'Factory.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}

	protected function processFileWithJson($factoryFile)
	{
		$data = self::getJsonContent();
		$model = collect($data['models'])->where('name', $this->ModelName)->first();
		$fileContent = file_get_contents($factoryFile);
		$definitionContent = $this->generateFactoryDefinition($model['props']);
		$updatedFileContent = str_replace('//EDIT//', $definitionContent, $fileContent);
		file_put_contents($factoryFile, $updatedFileContent);
	}

	private function generateFactoryDefinition(array $props)
	{
		$definition = '';
		foreach ($props as $index => $prop) {

			if($index == 0) {
				$definition .= "{$this->getFakerDefinitionForProperty($prop)}\n";
			} else if($index == count($props) - 1) {
				$definition .= "            {$this->getFakerDefinitionForProperty($prop)}";
			} else {
				$definition .= "            {$this->getFakerDefinitionForProperty($prop)}\n";
			}
		}
		return $definition;
	}

	private function getFakerDefinitionForProperty(array $prop)
	{
		// Note: También se puede retornar un valor en función del nombre de la propiedad

		// Determinar el tipo de dato y generar el valor faker correspondiente
		switch ($prop['type']) {
			case 'string':
				return "'{$prop['name']}' => \$this->faker->sentence(),";  // Ejemplo: para strings
			case 'longText':
				return "'{$prop['name']}' => \$this->faker->text(),";  // Ejemplo: para textos largos
			case 'foreignId':
				return "'{$prop['name']}' => 1,";  // Ejemplo: para llaves foráneas
			case 'integer':
				return "'{$prop['name']}' => \$this->faker->randomNumber(),";  // Ejemplo: para enteros
			// Puedes agregar más casos para otros tipos de datos que quieras manejar
			default:
				return "'{$prop['name']}' => null,";  // Valor por defecto si no se reconoce el tipo
		}
	}

}

<?php


namespace Innoboxrr\LarapackGenerator\Tools\Migration;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Illuminate\Support\Facades\Schema;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class MigrationTool extends Tool
{

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
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->init($ModelName)
			->setMigrationPath()
			->setMigrationTemplatePath();
	
		$migrationFile = $this->migrationPath . '/' . date('Y_m_d_His') . '_create_' . $this->plural_snake_case_model_name . '_table.php';
		// PENDIENTE: Cambiar esto para que en lugar de esta validación verifique si no existe esta misma clase en las migraciones de la aplicación
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/MigrationTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceData($migrationFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($migrationFile);
				}
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	public function remove(string $ModelName)
	{
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->init($ModelName)
			->setMigrationPath()
			->setMigrationTemplatePath();
		$migrationFilename = date('Y_m_d_His') . '_drop_' . $this->plural_snake_case_model_name . '_table.php';
		$migrationFile = $this->migrationPath . '/' . $migrationFilename;
		// Solo proceder en caso de los archivos no existan
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/DropTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				// Remplace dummy data
				$this->replaceData($migrationFile);
			} else {
				throw new MakerException;
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
			if($index == 0) {
				$columns .= "{$columnDefinition}\n";
			} else if($index == count($props) - 1) {
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

		// Agregar las propiedades adicionales como 'nullable', 'default', 'after', etc.
		if ($prop['nullable']) {
			$column .= "->nullable()";
		}
	
		if (!is_null($prop['default'])) {
			$column .= "->default('{$prop['default']}')";
		}
	
		/*
		if (!is_null($prop['after'])) {
			$column .= "->after('{$prop['after']}')";
		}
		*/
	
		// Agregar restricciones de clave foránea si las hay
		if ($prop['type'] === 'foreignId' && !is_null($prop['constraint'])) {
			$column .= "->constrained('{$prop['constraint']}')->onUpdate('cascade')->onDelete('cascade')";
		}
	
		return $column . ";";
	}
	
}

<?php

namespace Innoboxrr\LarapackGenerator\Tools\Model;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelTool extends Tool
{

	protected $modelPath;

	protected $modelTemplatePath;

	private function setModelPath()
	{
		$this->modelPath = get_path(app_dir_name() . '/Models');
		return $this;
	}

	private function setModelTemplatePath()
	{
		$this->modelTemplatePath = stubs_path('Model');
		return $this;
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName)
			->setModelPath()
			->setModelTemplatePath();

		$modelFile = $this->modelPath . '/' . $this->PascalCaseModelName . '.php';
		if(!file_exists($modelFile)) {
			$templateFile = $this->modelTemplatePath . '/ModelTemplate.txt';
			if(copy($templateFile, $modelFile)) {
				$this->replaceData($modelFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($modelFile);
				}
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	public function remove(string $ModelName)
	{
		$this->init($ModelName)
			->setModelPath();
		$path = $this->modelPath . '/' . $this->PascalCaseModelName . '.php';
		return (file_exists($path)) ? $this->dropFile($path) : false;
	}

	protected function processFileWithJson($modelFile)
	{
		// Obtener el contenido JSON
		$data = self::getJsonContent();

		// Recuperar la información del modelo
		$model = collect($data['models'])->where('name', $this->ModelName)->first();

		// Recuperar el contenido del archivo del modelo
		$fileContent = file_get_contents($modelFile);

		// Obtener los valores para los diferentes secciones de la plantilla
		$fillable = $this->generateListFromProps($model['props'], 'fillable');
		$creatable = $this->generateListFromProps($model['props'], 'creatable');
		$updatable = $this->generateListFromProps($model['props'], 'updatable');
		$casts = $this->generateCasts($model['props']);
		$editableMetas = $this->generateEditableMetas($model['editable_metas']);
		$exportCols = $this->generateExportCols($model['props']);
		$loadableRelations = $this->generateLoadableRelations($model['load_relations']);
		$loadableCounts = $this->generateLoadableCounts($model['load_counts']);

		// Reemplazar los marcadores en el archivo del modelo
		$updatedFileContent = str_replace('//FILLABLE//', $fillable, $fileContent);
		$updatedFileContent = str_replace('//CREATABLE//', $creatable, $updatedFileContent);
		$updatedFileContent = str_replace('//UPDATABLE//', $updatable, $updatedFileContent);
		$updatedFileContent = str_replace('//CASTS//', $casts, $updatedFileContent);
		$updatedFileContent = str_replace('//EDITABLEMETAS//', $editableMetas, $updatedFileContent);
		$updatedFileContent = str_replace('//EXPORTCOLS//', $exportCols, $updatedFileContent);
		$updatedFileContent = str_replace('//LOADABLERELATIONS//', $loadableRelations, $updatedFileContent);
		$updatedFileContent = str_replace('//LOADABLECOUNTS//', $loadableCounts, $updatedFileContent);

		// Guardar el archivo de nuevo con las modificaciones
		file_put_contents($modelFile, $updatedFileContent);
	}

	private function generateListFromProps(array $props, $field)
	{
		$list = [];

		foreach ($props as $prop) {
			if ($prop[$field]) {
				$list[] = "'{$prop['name']}'";
			}
		}

		return implode(', ', $list);
	}

	private function generateCasts(array $props)
	{
		$casts = [];

		foreach ($props as $prop) {
			if (!is_null($prop['cast'])) {
				$casts[] = "'{$prop['name']}' => '{$prop['cast']}'";
			}
		}

		return implode(', ', $casts);
	}

	private function generateEditableMetas(array $editableMetas)
	{
		if (empty($editableMetas)) {
			return '';
		}

		return implode(', ', array_map(fn($meta) => "'{$meta}'", $editableMetas));
	}

	private function generateExportCols(array $props)
	{
		$exportCols = [];

		foreach ($props as $prop) {
			if ($prop['exports_cols']) {
				$exportCols[] = "'{$prop['name']}'";
			}
		}

		return implode(', ', $exportCols);
	}

	private function generateLoadableRelations(array $loadRelations)
	{
		if (empty($loadRelations)) {
			return '';
		}

		return implode(', ', array_map(fn($relation) => "'{$relation['name']}'", $loadRelations));
	}

	private function generateLoadableCounts(array $loadCounts)
	{
		if (empty($loadCounts)) {
			return '';
		}

		return implode(', ', array_map(fn($count) => "'{$count}'", $loadCounts));
	}


}

<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelTraits;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelTraitsTool extends Tool
{

	protected $modelTraitsPath;
	protected $modelTraitsTemplatePath;
	protected $assignmentsPath;
	protected $mutatorsPath;
	protected $operationsPath;
	protected $queriesPath;
	protected $relationsPath;
	protected $storagePath;
	private function setModelTraitsPath()
	{
		$this->modelTraitsPath = get_path(app_dir_name() . '/Models/Traits');
		return $this;
	}

	private function setModelTraitsTemplatePath()
	{
		$this->modelTraitsTemplatePath = stubs_path('ModelTraits');
		return $this;
	}

	protected function setTraitsPath()
	{
		$this->assignmentsPath = $this->modelTraitsPath . '/Assignments';
		if (!file_exists($this->assignmentsPath)) mkdir($this->assignmentsPath, 0777, true);
		$this->mutatorsPath = $this->modelTraitsPath . '/Mutators';
		if (!file_exists($this->mutatorsPath)) mkdir($this->mutatorsPath, 0777, true);
		$this->operationsPath = $this->modelTraitsPath . '/Operations';
		if (!file_exists($this->operationsPath)) mkdir($this->operationsPath, 0777, true);
		$this->relationsPath = $this->modelTraitsPath . '/Relations';
		if (!file_exists($this->relationsPath)) mkdir($this->relationsPath, 0777, true);
		$this->storagePath = $this->modelTraitsPath . '/Storage';
		if (!file_exists($this->storagePath)) mkdir($this->storagePath, 0777, true);
		return $this;
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName)
			->setModelTraitsPath()
			->setModelTraitsTemplatePath()
			->setTraitsPath()
			->createTraits();
		return true;
	}

	private function createTraits()
	{
		$this->createAssignmentTrait()
			->createMutatorsTrait()
			->createOperationsTrait()
			->createRelationsTrait()
			->createStorageTrait();
		return $this;
	}

	private function createAssignmentTrait()
	{
		$assignmentTraitFile = $this->modelTraitsPath . '/Assignments/' . $this->PascalCaseModelName . 'Assignment.php';
		if(!file_exists($assignmentTraitFile)) {
			$templateFile = $this->modelTraitsTemplatePath . '/Assignments/AssignmentTemplate.txt';
			if(copy($templateFile, $assignmentTraitFile)) {
				$this->replaceData($assignmentTraitFile);
			} else {
				throw new MakerException;
			}
		} 
		return $this;
	}

	private function createMutatorsTrait()
	{
		$mutatorsTraitFile = $this->modelTraitsPath . '/Mutators/' . $this->PascalCaseModelName . 'Mutators.php';
		if(!file_exists($mutatorsTraitFile)) {
			$templateFile = $this->modelTraitsTemplatePath . '/Mutators/MutatorsTemplate.txt';
			if(copy($templateFile, $mutatorsTraitFile)) {
				$this->replaceData($mutatorsTraitFile);
			} else {
				throw new MakerException;
			}
		} 
		return $this;
	}

	private function createOperationsTrait()
	{
		$operationsTraitFile = $this->modelTraitsPath . '/Operations/' . $this->PascalCaseModelName . 'Operations.php';
		if(!file_exists($operationsTraitFile)) {
			$templateFile = $this->modelTraitsTemplatePath . '/Operations/OperationsTemplate.txt';
			if(copy($templateFile, $operationsTraitFile)) {
				$this->replaceData($operationsTraitFile);
			} else {
				throw new MakerException;
			}
		} 
		return $this;
	}

	private function createRelationsTrait()
	{
		$relationsTraitFile = $this->modelTraitsPath . '/Relations/' . $this->PascalCaseModelName . 'Relations.php';
		if(!file_exists($relationsTraitFile)) {
			$templateFile = $this->modelTraitsTemplatePath . '/Relations/RelationsTemplate.txt';
			if(copy($templateFile, $relationsTraitFile)) {
				$this->replaceData($relationsTraitFile);
				if(self::isFromJsonImporter()) {
					$this->processRelationsTraitWithJson($relationsTraitFile);
				}
			} else {
				throw new MakerException;
			}
		} 
		return $this;
	}

	private function processRelationsTraitWithJson($traitFile)
	{
		// Obtener el contenido del JSON
		$data = self::getJsonContent();
	
		// Encontrar el modelo actual basado en su nombre
		$model = collect($data['models'])->where('name', $this->ModelName)->first();
	
		// Obtener las relaciones
		$relations = $model['load_relations'] ?? [];
	
		$relationsCode = '';
		$imports = [];
	
		// Iterar a través de las relaciones
		foreach ($relations as $relation) {
			$relationType = $relation['type'];   // belongsTo, hasMany, etc.
			$relatedModel = $relation['related']; // Modelo relacionado (por ejemplo, User)
			$relationName = $relation['name'];   // Nombre de la relación (por ejemplo, user)
			$relatedNamespace = $relation['namespace'] ?? 'App\\Models'; // Namespace del modelo relacionado
	
			// Generar el código de la relación
			$relationsCode .= "    public function {$relationName}()\n";
			$relationsCode .= "    {\n";
			$relationsCode .= "        return \$this->{$relationType}({$relatedModel}::class);\n";
			$relationsCode .= "    }\n\n";
	
			// Agregar el import del modelo relacionado, si aún no está en la lista
			$importStatement = "use {$relatedNamespace}\\{$relatedModel};";
			if (!in_array($importStatement, $imports)) {
				$imports[] = $importStatement;
			}
		}
	
		// Cargar el contenido del archivo de plantilla
		$fileContent = file_get_contents($traitFile);
	
		// Unir los imports generados
		$importCode = implode("\n", $imports);
	
		// Reemplazar los placeholders con los imports y el código generado
		$fileContent = str_replace('//IMPORTS//', rtrim($importCode, "\n"), $fileContent);
		$fileContent = str_replace('//EDIT//', rtrim($relationsCode, "\n"), $fileContent);
	
		// Guardar el archivo modificado
		file_put_contents($traitFile, $fileContent);
	}	

	private function createStorageTrait()
	{
		$storageTraitFile = $this->modelTraitsPath . '/Storage/' . $this->PascalCaseModelName . 'Storage.php';
		if(!file_exists($storageTraitFile)) {
			$templateFile = $this->modelTraitsTemplatePath . '/Storage/StorageTemplate.txt';
			if(copy($templateFile, $storageTraitFile)) {
				$this->replaceData($storageTraitFile);
			} else {
				throw new MakerException;
			}
		} 
		return $this;
	}

	public function remove(string $ModelName)
	{
		$this->init($ModelName)
			->setModelTraitsPath()
			->setModelTraitsTemplatePath()
			->setTraitsPath()
			->removeTraits();
		return true;
	}

	private function removeTraits()
	{
		$this->removeAssignmentTrait()
			->removeMutatorsTrait()
			->removeOperationsTrait()
			->removeRelationsTrait()
			->removeStorageTrait();
		return $this;
	}

	private function removeAssignmentTrait()
	{
		$path = $this->modelTraitsPath . '/Assignments/' . $this->PascalCaseModelName . 'Assignment.php';
		$res = (file_exists($path)) ? $this->dropFile($path) : false;
		return $this;
	}

	private function removeMutatorsTrait()
	{
		$path = $this->modelTraitsPath . '/Mutators/' . $this->PascalCaseModelName . 'Mutators.php';
		$res = (file_exists($path)) ? $this->dropFile($path) : false;
		return $this;
	}

	private function removeOperationsTrait()
	{
		$path = $this->modelTraitsPath . '/Operations/' . $this->PascalCaseModelName . 'Operations.php';
		$res = (file_exists($path)) ? $this->dropFile($path) : false;
		return $this;
	}

	private function removeRelationsTrait()
	{
		$path = $this->modelTraitsPath . '/Relations/' . $this->PascalCaseModelName . 'Relations.php';
		$res = (file_exists($path)) ? $this->dropFile($path) : false;
		return $this;
	}

	private function removeStorageTrait()
	{
		$path = $this->modelTraitsPath . '/Storage/' . $this->PascalCaseModelName . 'Storage.php';
		$res = (file_exists($path)) ? $this->dropFile($path) : false;
		return $this;
	}

}

<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelViewTool extends Tool
{

	protected $errors;

	protected $modelViewModelJsPath;
	protected $modelViewRouteJsPath;
	protected $modelViewVuexJsPath;
	protected $modelViewCreateFormPath;
	protected $modelViewEditFormPath;
	protected $modelViewFilterFormPath;
	protected $modelViewAdminViewPath;
	protected $modelViewCreateViewPath;
	protected $modelViewEditViewPath;
	protected $modelViewShowViewPath;
	protected $modelViewDataTablePath;
	protected $modelViewModelCardPath;
	protected $modelViewModelProfilePath;
	
	protected $modelViewTemplateModelJsPath;
	protected $modelViewTemplateRouteJsPath;
	protected $modelViewTemplateVuexJsPath;
	protected $modelViewTemplateCreateFormPath;
	protected $modelViewTemplateEditFormPath;
	protected $modelViewTemplateFilterFormPath;
	protected $modelViewTemplateAdminViewPath;
	protected $modelViewTemplateCreateViewPath;
	protected $modelViewTemplateEditViewPath;
	protected $modelViewTemplateShowViewPath;
	protected $modelViewTemplateDataTablePath;
	protected $modelViewTemplateModelCardPath;
	protected $modelViewTemplateModelProfilePath;
	

	# ModelJS

		// Definir la ruta de la aplicación
		private function setModelViewModelJsPath()
		{
			$this->modelViewModelJsPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname);
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateModelJsPath()
		{
			$this->modelViewTemplateModelJsPath = stubs_path('ModelView');
			return $this;
		}

		// Crear
		public function createModelViewModelJS()
		{

			$this->setModelViewModelJsPath()
				->setModelViewTemplateModelJsPath();

			$modelFile = $this->modelViewModelJsPath . '/index.js';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateModelJsPath . '/model.js';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
					if(self::isFromJsonImporter()) {
						$this->processViewModelJSWithJson($modelFile);
					}
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

		private function processViewModelJSWithJson($modelFile)
		{
			// Obtener el contenido JSON
			$data = self::getJsonContent();
			$model = collect($data['models'])->where('name', $this->ModelName)->first();
			$props = $model['props'];
			$columns = '';
			$sort = '';

			// Generar el contenido para las columnas de la tabla y el ordenamiento
			foreach ($props as $index => $prop) {
				// Para las columnas
				if (isset($prop['datatable']) && $prop['datatable'] === true) {
					$columns .= "        {\n";
					$columns .= "            id: '{$prop['name']}',\n";
					$columns .= "            value: '" . ucfirst($prop['name']) . "',\n";
					$columns .= "            sortable: true,\n";
					$columns .= "            html: false,\n";
					$columns .= "            parser: (value) => {\n";
					$columns .= "                return value;\n";
					$columns .= "            }\n";
					$columns .= "        },\n";
				}

				// Para el ordenamiento (sort)
				if ($index === 0) {
					$sort = "        {$prop['name']}: 'asc',\n";
				}
			}

			// Cargar el contenido del archivo de plantilla
			$fileContent = file_get_contents($modelFile);
			$fileContent = str_replace('//DATA_TABLE_COLUMNS//', $columns, $fileContent);
			$fileContent = str_replace('//DATA_TABLE_SORT//', $sort, $fileContent);
			file_put_contents($modelFile, $fileContent);
		}


	# RouteJS

		// Definir la ruta de la aplicación
		private function setModelViewRouteJsPath()
		{
			$this->modelViewRouteJsPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/routes');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateRouteJsPath()
		{
			$this->modelViewTemplateRouteJsPath = stubs_path('ModelView');
			return $this;
		}

		// Crear
		public function createModelViewRouteJS()
		{
			$this->setModelViewRouteJsPath()
				->setModelViewTemplateRouteJsPath();

			$modelFile = $this->modelViewRouteJsPath . '/index.js';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateRouteJsPath . '/routes.js';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

	# VuexJS

		// Definir la ruta de la aplicación
		private function setModelViewVuexJsPath()
		{
			$this->modelViewVuexJsPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/vuex');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateVuexJsPath()
		{
			$this->modelViewTemplateVuexJsPath = stubs_path('ModelView');
			return $this;
		}

		// Crear
		public function createModelViewVuexJS()
		{
			$this->setModelViewVuexJsPath()
				->setModelViewTemplateVuexJsPath();

			$modelFile = $this->modelViewVuexJsPath . '/' . $this->camelCaseModelName . 'Model.js';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateVuexJsPath . '/vuex.js';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

	# Forms - CreateForm

		// Definir la ruta de la aplicación
		private function setModelViewCreateFormPath()
		{
			$this->modelViewCreateFormPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/forms');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateCreateFormPath()
		{
			$this->modelViewTemplateCreateFormPath = stubs_path('ModelView/forms');
			return $this;
		}

		// Crear
		public function createModelViewCreateForm()
		{
			$this->setModelViewCreateFormPath()
				->setModelViewTemplateCreateFormPath();

			$modelFile = $this->modelViewCreateFormPath . '/CreateForm.vue';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateCreateFormPath . '/CreateForm.vue';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
					if(self::isFromJsonImporter()) {
						$this->processCreateFormWithJson($modelFile);
					}
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

		private function processCreateFormWithJson($modelFile)
		{
			// Obtener el contenido JSON
			$data = self::getJsonContent();
		
			// Recuperar la información del modelo actual
			$model = collect($data['models'])->where('name', $this->ModelName)->first();
		
			// Obtener las props del modelo
			$props = $model['props'];
		
			// Inicializar las cadenas para los inputs y otros bloques
			$inputs = '';
			$importComponents = '';
			$registerComponents = '';
			$dataFields = '';
			$submitData = '';
			$propsData = '';
		
			// Lista de componentes ya añadidos (para evitar duplicados)
			$addedComponents = ['TextInputComponent']; // TextInputComponent ya está registrado por defecto
		
			// Generar el contenido basado en las props
			foreach ($props as $prop) {
				if ($prop['form']) {
					$componentName = $prop['form_component'] ?? null;
		
					// Lógica para generar los componentes según su tipo
					switch ($componentName) {
						case 'TextInputComponent':
							$inputs .= "        <text-input-component\n"; // 4 espacios para la indentación
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            type=\"text\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required length\"\n";
							$inputs .= "            min_length=\"3\"\n";
							$inputs .= "            max_length=\"130\"\n";
							$inputs .= "            v-model=\"{$prop['name']}\" />\n";
							break;
		
						case 'SelectInputComponent':
							$inputs .= "        <select-input-component\n";
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required\"\n";
							$inputs .= "            v-model=\"{$prop['name']}\">\n";
		
							// Generar dinámicamente las opciones a partir del enum en el JSON
							$options = '';
							if (isset($prop['enum'])) {
								foreach ($prop['enum'] as $value => $label) {
									$options .= "            <option value=\"{$value}\">{{ __('{$label}') }}</option>\n"; // 8 espacios para las opciones
								}
							} else {
								// Opciones por defecto en caso de que no haya enum
								$options = "            <option value=\"\">{{ __('Select') }}</option>\n";
							}
							$inputs .= $options;
							$inputs .= "        </select-input-component>\n"; // cerrar componente con 4 espacios
							break;
		
						case 'TextareaInputComponent':
							$inputs .= "        <textarea-input-component\n";
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required length\"\n";
							$inputs .= "            min_length=\"3\"\n";
							$inputs .= "            max_length=\"1500\"\n";
							$inputs .= "            v-model=\"{$prop['name']}\" />\n";
							break;
		
						case 'EditorInputComponent':
							$inputs .= "        <editor-input-component\n";
							$inputs .= "            :id=\"`{$prop['name']}-\${formId}`\"\n";
							$inputs .= "            :file=\"true\"\n";
							$inputs .= "            :uploadUrl=\"fileUploadUrl\"\n";
							$inputs .= "            :on-file-upload-success=\"handleFileUploadSuccess\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :height=\"300\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required\"\n";
							$inputs .= "            v-model=\"{$prop['name']}\" />\n";
							break;
		
						default:
							// Si no hay componente configurado, añadir comentario
							$inputs .= "        <!-- {$prop['name']} input not configured, add a component -->\n";
							break;
					}
		
					// Importar y registrar el componente si existe y no ha sido añadido antes
					if ($componentName && !in_array($componentName, $addedComponents)) {
						$importComponents .= "        {$componentName},\n";
						$registerComponents .= "            {$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}
		
					// Añadir al bloque de datos
					$dataFields .= "                {$prop['name']}: '',\n";
				}
		
				// Manejo de form_submit
				if ($prop['form_submit']) {
					$submitData .= "                        {$prop['name']}: this.{$prop['name']},\n";
				}
		
				// Si form es false pero form_submit es true
				if (!$prop['form'] && $prop['form_submit']) {
					$propsData .= "            {$prop['name']}: '',\n";
				}
			}
		
			// Cargar el contenido del archivo de plantilla
			$fileContent = file_get_contents($modelFile);
		
			// Reemplazar los placeholders
			$fileContent = str_replace('<!-- Add more inputs -->', rtrim($inputs, "\n"), $fileContent);
			$fileContent = str_replace('//import_more_components//', rtrim($importComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//register_more_components//', rtrim($registerComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//add_more_data//', rtrim($dataFields, ",\n"), $fileContent);
			$fileContent = str_replace('//submit_data//', rtrim($submitData, ",\n"), $fileContent);
			$fileContent = str_replace('//props//', rtrim($propsData, ",\n"), $fileContent);
		
			// Guardar el archivo modificado
			file_put_contents($modelFile, $fileContent);
		}		


	# Forms - EditForm

		// Definir la ruta de la aplicación
		private function setModelViewEditFormPath()
		{
			$this->modelViewEditFormPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/forms');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateEditFormPath()
		{
			$this->modelViewTemplateEditFormPath = stubs_path('ModelView/forms');
			return $this;
		}

		// Crear
		public function createModelViewEditForm()
		{

			$this->setModelViewEditFormPath()
				->setModelViewTemplateEditFormPath();

			$modelFile = $this->modelViewEditFormPath . '/EditForm.vue';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateEditFormPath . '/EditForm.vue';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
					if(self::isFromJsonImporter()) {
						$this->processEditFormWithJson($modelFile);
					}
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

		private function processEditFormWithJson($modelFile)
		{
			// Obtener el contenido JSON
			$data = self::getJsonContent();
		
			// Recuperar la información del modelo actual
			$model = collect($data['models'])->where('name', $this->ModelName)->first();

			$camelCaseModelName = $this->camelCaseModelName;
		
			// Obtener las props del modelo
			$props = $model['props'];
		
			// Inicializar las cadenas para los inputs y otros bloques
			$inputs = '';
			$importComponents = '';
			$registerComponents = '';
			$modelDataFields = '';
			$submitData = '';
			$propsData = '';
		
			// Lista de componentes ya añadidos (para evitar duplicados)
			$addedComponents = ['TextInputComponent']; // TextInputComponent ya está registrado por defecto
		
			// Generar el contenido basado en las props
			foreach ($props as $prop) {
				if ($prop['form']) {
					$componentName = $prop['form_component'] ?? null;
		
					// Lógica para generar los componentes según su tipo
					switch ($componentName) {
						case 'TextInputComponent':
							$inputs .= "        <text-input-component\n"; // 4 espacios para la indentación
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            type=\"text\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required length\"\n";
							$inputs .= "            min_length=\"3\"\n";
							$inputs .= "            max_length=\"130\"\n";
							$inputs .= "            v-model=\"{$camelCaseModelName}.{$prop['name']}\" />\n";
							break;
		
						case 'SelectInputComponent':
							$inputs .= "        <select-input-component\n";
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required\"\n";
							$inputs .= "            v-model=\"{$camelCaseModelName}.{$prop['name']}\">\n";
		
							// Generar dinámicamente las opciones a partir del enum en el JSON
							$options = '';
							if (isset($prop['enum'])) {
								foreach ($prop['enum'] as $value => $label) {
									$options .= "            <option value=\"{$value}\">{{ __('{$label}') }}</option>\n"; // 8 espacios para las opciones
								}
							} else {
								// Opciones por defecto en caso de que no haya enum
								$options = "            <option value=\"\">{{ __('Select') }}</option>\n";
							}
							$inputs .= $options;
							$inputs .= "        </select-input-component>\n"; // cerrar componente con 4 espacios
							break;
		
						case 'TextareaInputComponent':
							$inputs .= "        <textarea-input-component\n";
							$inputs .= "            :custom-class=\"inputClass\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required length\"\n";
							$inputs .= "            min_length=\"3\"\n";
							$inputs .= "            max_length=\"1500\"\n";
							$inputs .= "            v-model=\"{$camelCaseModelName}.{$prop['name']}\" />\n";
							break;
		
						case 'EditorInputComponent':
							$inputs .= "        <editor-input-component\n";
							$inputs .= "            :id=\"`{$prop['name']}-\${formId}`\"\n";
							$inputs .= "            :file=\"true\"\n";
							$inputs .= "            :uploadUrl=\"fileUploadUrl\"\n";
							$inputs .= "            :on-file-upload-success=\"handleFileUploadSuccess\"\n";
							$inputs .= "            name=\"{$prop['name']}\"\n";
							$inputs .= "            :height=\"300\"\n";
							$inputs .= "            :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
							$inputs .= "            validators=\"required\"\n";
							$inputs .= "            v-model=\"{$camelCaseModelName}.{$prop['name']}\" />\n";
							break;
		
						default:
							// Si no hay componente configurado, añadir comentario
							$inputs .= "        <!-- {$prop['name']} input not configured, add a component -->\n";
							break;
					}
		
					// Importar y registrar el componente si existe y no ha sido añadido antes
					if ($componentName && !in_array($componentName, $addedComponents)) {
						$importComponents .= "        {$componentName},\n";
						$registerComponents .= "            {$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}
		
					// Añadir al bloque de datos del modelo
					$modelDataFields .= "                    {$prop['name']}: '',\n";
				}
		
				// Manejo de form_submit
				if ($prop['form_submit']) {
					$submitData .= "                        {$prop['name']}: this.{$camelCaseModelName}.{$prop['name']},\n";
				}
		
				// Si form es false pero form_submit es true
				if (!$prop['form'] && $prop['form_submit']) {
					$propsData .= "                            {$prop['name']}: '',\n";
				}
			}
		
			// Cargar el contenido del archivo de plantilla
			$fileContent = file_get_contents($modelFile);
		
			// Reemplazar los placeholders
			$fileContent = str_replace('<!-- Add more inputs -->', rtrim($inputs, "\n"), $fileContent);
			$fileContent = str_replace('//import_more_components//', rtrim($importComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//register_more_components//', rtrim($registerComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//model_data//', rtrim($modelDataFields, ",\n"), $fileContent);
			$fileContent = str_replace('//submit_data//', rtrim($submitData, ",\n"), $fileContent);
			$fileContent = str_replace('//props//', rtrim($propsData, ",\n"), $fileContent);
		
			// Guardar el archivo modificado
			file_put_contents($modelFile, $fileContent);
		}		


	# Forms - FilterForm

		// Definir la ruta de la aplicación
		private function setModelViewFilterFormPath()
		{
			$this->modelViewFilterFormPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/forms');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateFilterFormPath()
		{
			$this->modelViewTemplateFilterFormPath = stubs_path('ModelView/forms');
			return $this;
		}

		// Crear
		public function createModelViewFilterForm()
		{
			$this->setModelViewFilterFormPath()
				->setModelViewTemplateFilterFormPath();

			$modelFile = $this->modelViewFilterFormPath . '/FilterForm.vue';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateFilterFormPath . '/FilterForm.vue';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
					if(self::isFromJsonImporter()) {
						$this->processFilterFormWithJson($modelFile);
					}
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

		private function processFilterFormWithJson($modelFile)
		{
			// Obtener el contenido JSON
			$data = self::getJsonContent();
			
			// Recuperar la información del modelo actual
			$model = collect($data['models'])->where('name', $this->ModelName)->first();
			
			// Obtener las props del modelo
			$props = $model['props'];
			
			// Inicializar las cadenas para los inputs, componentes, y otras secciones
			$inputs = '';
			$importComponents = '';
			$registerComponents = '';
			$dataFields = '';
			$resetInputs = '';
			
			// Lista de componentes ya añadidos (para evitar duplicados)
			$addedComponents = ['TextInputComponent']; // TextInputComponent ya está registrado por defecto
			
			// Generar el contenido basado en las props
			foreach ($props as $prop) {
				// Solo procesar si la propiedad está habilitada para formularios (form: true)
				if ($prop['form'] && $prop['name'] !== 'id') {
					// Si la propiedad tiene enum, usamos SelectInputComponent
					if (isset($prop['enum'])) {
						$componentName = 'SelectInputComponent';
						
						// Generar dinámicamente las opciones del enum
						$options = '';
						foreach ($prop['enum'] as $value => $label) {
							$options .= "                <option value=\"{$value}\">{{ __('{$label}') }}</option>\n";
						}
						
						$inputs .= "            <div>\n";
						$inputs .= "                <select-input-component\n";
						$inputs .= "                    :custom-class=\"inputClass\"\n";
						$inputs .= "                    name=\"{$prop['name']}\"\n";
						$inputs .= "                    :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
						$inputs .= "                    v-model=\"{$prop['name']}\">\n";
						$inputs .= $options;
						$inputs .= "                </select-input-component>\n";
						$inputs .= "            </div>\n";
					} else {
						// Usar TextInputComponent para cualquier otro tipo
						$componentName = 'TextInputComponent';
						
						$inputs .= "            <div>\n";
						$inputs .= "                <text-input-component\n";
						$inputs .= "                    :custom-class=\"inputClass\"\n";
						$inputs .= "                    type=\"text\"\n";
						$inputs .= "                    name=\"{$prop['name']}\"\n";
						$inputs .= "                    :label=\"__('" . ucfirst($prop['name']) . "')\"\n";
						$inputs .= "                    :placeholder=\"__('" . ucfirst($prop['name']) . "')\"\n";
						$inputs .= "                    v-model=\"{$prop['name']}\" />\n";
						$inputs .= "            </div>\n";
					}
					
					// Importar y registrar el componente si existe y no ha sido añadido antes
					if (!in_array($componentName, $addedComponents)) {
						$importComponents .= "        {$componentName},\n";
						$registerComponents .= "            {$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}
					
					// Añadir al bloque de data
					$dataFields .= "                {$prop['name']}: null,\n";
					
					// Añadir al bloque de reseteo
					$resetInputs .= "                this.{$prop['name']} = null;\n";
				}
			}
			
			// Cargar el contenido del archivo de plantilla
			$fileContent = file_get_contents($modelFile);
			
			// Reemplazar los placeholders
			$fileContent = str_replace('<!-- Add more inputs -->', rtrim($inputs, "\n"), $fileContent);
			$fileContent = str_replace('//import_more_components//', rtrim($importComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//register_more_components//', rtrim($registerComponents, ",\n"), $fileContent);
			$fileContent = str_replace('//add_more_data//', rtrim($dataFields, ",\n"), $fileContent);
			$fileContent = str_replace('//reset_inputs//', rtrim($resetInputs, "\n"), $fileContent);
			
			// Guardar el archivo modificado
			file_put_contents($modelFile, $fileContent);
		}
		
	# Views - AdminView

		// Definir la ruta de la aplicación
		private function setModelViewAdminViewPath()
		{
			$this->modelViewAdminViewPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/views');
			return $this;
		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateAdminViewPath()
		{
			$this->modelViewTemplateAdminViewPath = stubs_path('ModelView/views');
			return $this;
		}

		// Crear
		public function createModelViewAdminView()
		{
			$this->setModelViewAdminViewPath()
				->setModelViewTemplateAdminViewPath();

			$modelFile = $this->modelViewAdminViewPath . '/AdminView.vue';

			if(!file_exists($modelFile)) {
				$templateFile = $this->modelViewTemplateAdminViewPath . '/AdminView.vue';
				if(copy($templateFile, $modelFile)) {
					$this->replaceData($modelFile);
				} else {
					throw new MakerException;
				}
			}
			return $this;
		}

	# Views - CreateView

		// Definir la ruta de la aplicación
		private function setModelViewCreateViewPath()
		{

			$this->modelViewCreateViewPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateCreateViewPath()
		{

			$this->modelViewTemplateCreateViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewCreateView()
		{

			$this->setModelViewCreateViewPath()
				->setModelViewTemplateCreateViewPath();

			$modelFile = $this->modelViewCreateViewPath . '/CreateView.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateCreateViewPath . '/CreateView.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Views - EditView

		// Definir la ruta de la aplicación
		private function setModelViewEditViewPath()
		{

			$this->modelViewEditViewPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateEditViewPath()
		{

			$this->modelViewTemplateEditViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewEditView()
		{

			$this->setModelViewEditViewPath()
				->setModelViewTemplateEditViewPath();

			$modelFile = $this->modelViewEditViewPath . '/EditView.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateEditViewPath . '/EditView.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Views - ShowView

		// Definir la ruta de la aplicación
		private function setModelViewShowViewPath()
		{

			$this->modelViewShowViewPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateShowViewPath()
		{

			$this->modelViewTemplateShowViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewShowView()
		{

			$this->setModelViewShowViewPath()
				->setModelViewTemplateShowViewPath();

			$modelFile = $this->modelViewShowViewPath . '/ShowView.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateShowViewPath . '/ShowView.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Widgets - DataTable

		// Definir la ruta de la aplicación
		private function setModelViewDataTablePath()
		{

			$this->modelViewDataTablePath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/widgets');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateDataTablePath()
		{

			$this->modelViewTemplateDataTablePath = stubs_path('ModelView/widgets');

			return $this;

		}

		// Crear
		public function createModelViewDataTable()
		{

			$this->setModelViewDataTablePath()
				->setModelViewTemplateDataTablePath();

			$modelFile = $this->modelViewDataTablePath . '/DataTable.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateDataTablePath . '/DataTable.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Widgets - ModelCard

		// Definir la ruta de la aplicación
		private function setModelViewModelCardPath()
		{

			$this->modelViewModelCardPath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/widgets');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateModelCardPath()
		{

			$this->modelViewTemplateModelCardPath = stubs_path('ModelView/widgets');

			return $this;

		}

		// Crear
		public function createModelViewModelCard()
		{

			$this->setModelViewModelCardPath()
				->setModelViewTemplateModelCardPath();

			$modelFile = $this->modelViewModelCardPath . '/ModelCard.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateModelCardPath . '/ModelCard.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Widgets - ModelProfile

		// Definir la ruta de la aplicación
		private function setModelViewModelProfilePath()
		{

			$this->modelViewModelProfilePath = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname . '/widgets');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateModelProfilePath()
		{

			$this->modelViewTemplateModelProfilePath = stubs_path('ModelView/widgets');

			return $this;

		}

		// Crear
		public function createModelViewModelProfile()
		{

			$this->setModelViewModelProfilePath()
				->setModelViewTemplateModelProfilePath();

			$modelFile = $this->modelViewModelProfilePath . '/ModelProfile.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateModelProfilePath . '/ModelProfile.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	//////////////////////
	/////// MAIN /////////
	//////////////////////	

		public function create(string $ModelName)
		{
			if(app_dir_name() == 'app') {
				$this->init($ModelName)
					->createModelViewModelJS()
					->createModelViewRouteJS()
					->createModelViewVuexJS()
					->createModelViewCreateForm()
					->createModelViewEditForm()
					->createModelViewFilterForm()
					->createModelViewAdminView()
					->createModelViewCreateView()
					->createModelViewEditView()
					->createModelViewShowView()
					->createModelViewDataTable()
					->createModelViewModelCard()
					->createModelViewModelProfile();
				return true;
			}
			return false;
		}

		public function remove(string $ModelName)
		{
			if(app_dir_name() == 'app') {
				$this->init($ModelName);
				$path = get_path('resources/vue/app/sections/admin/models/' . $this->kebabcasemodelname);
				$this->dropDir($path);
				return true;
			}
			return false;
		}

}

<?php

namespace Innoboxrr\LarapackGenerator\Tools\PivotMigration;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class PivotMigrationTool extends Tool
{

	protected $migrationPath;
	protected $migrationTemplatePath;
	protected $migrationName;

	private function setMigrationPath()
	{
		$this->migrationPath = get_path('database/migrations');
		return $this;
	}

	private function setPivotMigrationTemplatePath()
	{
		$this->migrationTemplatePath = stubs_path('PivotMigration');
		return $this;
	}

	public function create(string $migrationName)
	{
		$this->migrationName = $migrationName;
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->setMigrationPath()
			->setPivotMigrationTemplatePath();
		$migrationFile = $this->migrationPath . '/' . date('Y_m_d_His') . '_create_' . $migrationName . '_table.php';
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/MigrationTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceMigrationData($migrationFile);
				if(self::isFromJsonImporter()) {
					$this->processFileWithJson($migrationFile);
				}
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	public function remove(string $migrationName)
	{
		$this->migrationName = $migrationName;
		// Asegurarte de que la zona horaria sea la correcta
		date_default_timezone_set('UTC');
		$this->setMigrationPath()
			->setPivotMigrationTemplatePath();
		$migrationFilename = date('Y_m_d_His') . '_drop_' . $migrationName . '_table.php';
		$migrationFile = $this->migrationPath . '/' . $migrationFilename;
		// Solo proceder en caso de los archivos no existan
		if(!file_exists($migrationFile)) {
			$templateFile = $this->migrationTemplatePath . '/DropTemplate.txt';
			if(copy($templateFile, $migrationFile)) {
				$this->replaceMigrationData($migrationFile);
			} else {
				throw new MakerException;
			}
		} else {
			return false;
		}
		return true;
	}

	private function replaceMigrationData($migrationFile)
	{
		$content = file_get_contents($migrationFile);
		$content = str_replace("migration_name", $this->migrationName, $content);
		file_put_contents($migrationFile, $content);
	}

	protected function processFileWithJson($migrationFile)
	{
		$data = self::getJsonContent();
		$pivot = collect($data['pivots'])->where('name', $this->migrationName)->first();
		$fileContent = file_get_contents(filename: $migrationFile);
		$columnsSchema = $this->generateMigrationColumns($pivot['props']);
		$updatedFileContent = str_replace('//EDIT//', $columnsSchema, $fileContent);
		file_put_contents($migrationFile, $updatedFileContent);
	}
	
	private function generateMigrationColumns(array $props)
	{
		$columns = '';
		foreach ($props as $index => $prop) {
			$columnDefinition = $this->getColumnDefinition($prop);
			if($index == 0) {
				$columns .= "{$columnDefinition}\n";
			} else if($index == count($props) - 1) {
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

		// Agregar las propiedades adicionales como 'nullable', 'default', 'after', etc.
		if ($prop['nullable']) {
			$column .= "->nullable()";
		}
	
		if (!is_null($prop['default'])) {
			$column .= "->default('{$prop['default']}')";
		}
	
		/*
		if (!is_null($prop['after'])) {
			$column .= "->after('{$prop['after']}')";
		}
		*/
	
		// Agregar restricciones de clave foránea si las hay
		if ($prop['type'] === 'foreignId' && !is_null($prop['constraint'])) {
			$column .= "->constrained('{$prop['constraint']}')->onUpdate('cascade')->onDelete('cascade')";
		}
	
		return $column . ";";
	}
	
}

Ten en cuenta que los unicos vaores psoibles para form_component son:
 - TextInputComponent
 - SelectInputComponent
 - TextareaInputComponent
 - EditorInputComponent

Te voy a pasar unos modelos con sus propiedades y lo tienes que convertir en este JSON