<?php

namespace Hrauvc\LarapackGenerator\Tools;

// Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\Inflector; 
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;

class Tool
{	

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

		protected function replaceData($file)
		{

			$content = file_get_contents($file);

			// EL ORDEN DE REEMPLAZO SI IMPORTA

			// PLURALES
			 
			$content = str_replace("pluralModelName", $this->pluralModelName, $content);

        	$content = str_replace("plural_snake_case_model_name", $this->plural_snake_case_model_name, $content);

        	$content = str_replace("pluralCamelCaseModelName", $this->pluralCamelCaseModelName, $content);

        	$content = str_replace("PluralPascalCaseModelName", $this->PluralPascalCaseModelName, $content);

        	$content = str_replace("pluralkebabcasemodelname", $this->pluralkebabcasemodelname, $content);

        	$content = str_replace("pluralDotModelName", $this->pluralDotModelName, $content);

        	// SINGULARES

        	$content = str_replace("snake_case_model_name", $this->snake_case_model_name, $content);

        	$content = str_replace("camelCaseModelName", $this->camelCaseModelName, $content);

        	$content = str_replace("PascalCaseModelName", $this->PascalCaseModelName, $content);

        	$content = str_replace("kebabcasemodelname", $this->kebabcasemodelname, $content);

        	$content = str_replace("dotModelName", $this->dotModelName, $content);

        	$content = str_replace("ModelName", $this->ModelName, $content);

        	// NAMESPACE

        	$content = str_replace("Namespace\\", $this->namespace, $content);

        	$content = str_replace("dotNamespace", $this->dotNamespace, $content);

        	$content = str_replace("kebabNamespace", $this->kebabNamespace, $content);

        	$content = str_replace("namespaceWithoutSeparation", $this->namespaceWithoutSeparation, $content);

        	$content = str_replace("lowerNamespace", $this->lowerNamespace, $content);

        	$content = str_replace("slashLowerNamespace", $this->slashLowerNamespace, $content);

        	file_put_contents($file, $content);
			
		}

		public function addProvidersToComposerJson(array $providers) {

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

}