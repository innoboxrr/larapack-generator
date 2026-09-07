<?php

namespace Innoboxrr\LarapackGenerator\Tools;

// Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\Inflector; 
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;

class Tool
{	

	protected static $fromJsonImporter = false;
	protected static $jsonContent;

	// INFLECTOR
		protected $inflector;

	// NAMESPACE
		protected $namespace;
		protected $dotNamespace;
		protected $kebabNamespace;
		protected $namespaceWithoutSeparation;
		protected $lowerNamespace;
		protected $slashLowerNamespace;

	// MODEL NAME
		protected $ModelName;
		protected $snake_case_model_name;
		protected $camelCaseModelName;
		protected $PascalCaseModelName;
		protected $kebabcasemodelname;
		protected $dotModelName;
		protected $pluralModelName;
		protected $plural_snake_case_model_name;
		protected $pluralCamelCaseModelName;
		protected $PluralPascalCaseModelName;
		protected $pluralkebabcasemodelname;
		protected $pluralDotModelName;

	// SET FROM JSON IMPORTER
		public static function setFromJsonImporter(bool $value)
		{
			self::$fromJsonImporter = $value;
		}

		public static function isFromJsonImporter(): bool
		{
			return self::$fromJsonImporter;
		}

		public static function setJsonContent(array $content)
		{
			self::$jsonContent = $content;
		}

		public static function getJsonContent(): array
		{
			return self::$jsonContent;
		}

		protected function processFileWithJson($fileToProcess)
		{
			return;
		}
	
	// INIT

		/**
		 * 	@var $ModelName: 
		 *  	- Debe corresponder con el nombre del modelo que se está creando
		 *   	- Debe estar escrito en PascalCase
		 **/
		protected function init(string $ModelName)
		{
			$this->inflector = new Inflector(new NoopWordInflector(), new NoopWordInflector());
			$this->namespace = get_namespace();
			$this->dotNamespace = get_dot_namespace();
			$this->kebabNamespace = get_kebab_namespace();
			$this->namespaceWithoutSeparation = str_replace('.', '', mb_strtolower($this->dotNamespace));
			$this->lowerNamespace = mb_strtolower($this->namespace);
			$this->slashLowerNamespace = str_replace('\\', '/', $this->lowerNamespace);
			$this->setModelNames($ModelName);
			return $this;
		}

	// MODEL NAME

		private function setModelNames($ModelName)
		{
			$this->ModelName = $ModelName;
			$this->snake_case_model_name = $this->inflector->tableize($this->ModelName);
			$this->camelCaseModelName = $this->inflector->camelize($this->snake_case_model_name);
			$this->PascalCaseModelName = $this->inflector->classify($this->snake_case_model_name);
			$this->kebabcasemodelname = str_replace('_', '-', $this->snake_case_model_name);
			$this->dotModelName = str_replace('_', '.', $this->snake_case_model_name);
			$this->pluralModelName = Pluralizer::plural($this->ModelName);
			$this->plural_snake_case_model_name = Pluralizer::plural($this->snake_case_model_name);
			$this->pluralCamelCaseModelName = Pluralizer::plural($this->camelCaseModelName);
			$this->PluralPascalCaseModelName = Pluralizer::plural($this->PascalCaseModelName);
			$this->pluralkebabcasemodelname = Pluralizer::plural($this->kebabcasemodelname);
			$this->pluralDotModelName = Pluralizer::plural($this->dotModelName);
		}

	// REEMPLAZAR NOMBRES

		/**
		 * Tokens que los stubs usan como marcador, con su valor para el
		 * modelo actual.
		 *
		 * @return array<string, string>
		 */
		protected function replacementMap(): array
		{
			return [
				// PLURALES
				'pluralModelName' => $this->pluralModelName,
				'plural_snake_case_model_name' => $this->plural_snake_case_model_name,
				'pluralCamelCaseModelName' => $this->pluralCamelCaseModelName,
				'PluralPascalCaseModelName' => $this->PluralPascalCaseModelName,
				'pluralkebabcasemodelname' => $this->pluralkebabcasemodelname,
				'pluralDotModelName' => $this->pluralDotModelName,
				// SINGULARES
				'snake_case_model_name' => $this->snake_case_model_name,
				'camelCaseModelName' => $this->camelCaseModelName,
				'PascalCaseModelName' => $this->PascalCaseModelName,
				'kebabcasemodelname' => $this->kebabcasemodelname,
				'dotModelName' => $this->dotModelName,
				'ModelName' => $this->ModelName,
				// NAMESPACE
				'Namespace\\' => $this->namespace,
				'dotNamespace' => $this->dotNamespace,
				'kebabNamespace' => $this->kebabNamespace,
				'namespaceWithoutSeparation' => $this->namespaceWithoutSeparation,
				'lowerNamespace' => $this->lowerNamespace,
				'slashLowerNamespace' => $this->slashLowerNamespace,
			];
		}

		/**
		 * Sustituye los tokens del stub en una sola pasada.
		 *
		 * strtr() con un array prueba las claves de mayor a menor longitud y
		 * no vuelve a recorrer lo que ya ha sustituido. Eso hace innecesario
		 * el orden manual que habia aqui (los plurales antes que los
		 * singulares) e impide que un valor insertado vuelva a coincidir con
		 * otro token.
		 */
		protected function replaceTokens(string $content): string
		{
			return strtr($content, $this->replacementMap());
		}

		protected function replaceData($file)
		{
			file_put_contents($file, $this->replaceTokens(file_get_contents($file)));
		}

		public function addProvidersToComposerJson(array $providers) 
		{
			if(app_dir_name() == 'src') {
				$composerJsonPath = root_path() . '/composer.json';
			    $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
			    if (!isset($composerJsonData['extra']['laravel']['providers'])) {
			        $composerJsonData['extra']['laravel']['providers'] = [];
			    }
			    $composerJsonData['extra']['laravel']['providers'] = array_merge(
			        $composerJsonData['extra']['laravel']['providers'],
			        $providers
			    );
			    file_put_contents(
			    	$composerJsonPath, 
			    	json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
			    );
			}
		}

	// TOOLS
	
		protected function dropFile(string $file)
		{
			return unlink($file);
		}

		protected function dropDir(string $path)
		{
			$files = glob($path . '/{,.}[!.,!..]*',GLOB_MARK|GLOB_BRACE);
			foreach ($files as $file) {
				if (is_dir($file)) {
					$this->dropDir($file);
				} else {
					unlink($file);
				}
			}
			return rmdir($path);
		}
}