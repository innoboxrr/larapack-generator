<?php

namespace Innoboxrr\LarapackGenerator\Tools;

// Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\Inflector; 
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Manifest;

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
		protected $packageName;
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

	// MANIFIESTO
		protected ?Manifest $manifest = null;

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
			// El kebab del namespace acaba en separador (innoboxrr-deals-); el
			// nombre de paquete npm no puede llevarlo.
			$this->packageName = rtrim($this->kebabNamespace, "-");
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
				'packageName' => $this->packageName,
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

	// GENERACION

		/**
		 * Copia un stub al destino, sustituye sus tokens y, si venimos del
		 * importador JSON, aplica el post-proceso con los datos del modelo.
		 *
		 * Este era el cuerpo que cada tool repetia en su create(), con la
		 * salvedad de que un copy() fallido lanzaba una MakerException vacia:
		 * ahora dice que plantilla y que destino.
		 *
		 * @return bool  true si se escribio (o se escribiria, en dry-run).
		 */
		protected function generate(string $stub, string $destination): bool
		{
			if (! is_file($stub)) {
				throw MakerException::stubNotFound($stub);
			}

			$exists = file_exists($destination);

			if ($exists && ! Generation::isForced()) {
				Generation::record('skipped', $destination, $stub, 'ya existe');

				return false;
			}

			// Con --force se regenera, pero nunca sobre algo que se haya
			// editado a mano: eso es exactamente lo que el manifiesto permite
			// distinguir. Sin esa guarda, regenerar destruiria trabajo.
			if ($exists && $this->manifest()->wasCustomised($destination)) {
				Generation::record('preserved', $destination, $stub, 'editado a mano');

				return false;
			}

			if (Generation::isDryRun()) {
				Generation::record($exists ? 'overwrite' : 'create', $destination, $stub);

				return true;
			}

			$directory = dirname($destination);

			if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
				throw MakerException::directoryNotCreated($directory);
			}

			if (! copy($stub, $destination)) {
				throw MakerException::copyFailed($stub, $destination);
			}

			$this->replaceData($destination);

			if (self::isFromJsonImporter()) {
				$this->processFileWithJson($destination);
			}

			$this->manifest()->record($this->ModelName, $this->namespace, $destination, $stub);

			Generation::record($exists ? 'overwrite' : 'create', $destination, $stub);

			return true;
		}

		protected function manifest(): Manifest
		{
			return $this->manifest ??= new Manifest();
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