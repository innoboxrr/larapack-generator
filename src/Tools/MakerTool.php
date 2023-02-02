<?php

namespace Desar\Generator\Tools;

use Doctrine\Inflector\Inflector; // Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;

class MakerTool
{	

	// INFLECTOR

		protected $inflector;

	// NAMESPACE
	
		protected $namespace;

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

	// PATHS

		protected $adminRoutePath;

		protected $adminViewPath;

		protected $modelFormPath;

		protected $createFormPath;

		protected $createViewPath;

		protected $crudPath;

		protected $editFormPath;

		protected $editViewPath;

		protected $filterFormPath;

		protected $jsModelPath;

		protected $showViewPath;

	// REGISTER FILES

		protected $apiRouteModelFile;

		protected $eventServiceProviderFile;

		protected $authServiceProviderFile;

	// TEMPLATE PATHS

		protected $adminRouteTemplatePath;

		protected $adminViewTemplatePath;

		protected $createFormTemplatePath;

		protected $createViewTemplatePath;

		protected $crudTemplatePath;

		protected $editFormTemplatePath;

		protected $editViewTemplatePath;

		protected $filterFormTemplatePath;

		protected $jsModelTemplatePath;

		protected $showViewTemplatePath;

		/**
		 * 	@var $ModelName: 
		 *  	- Debe corresponder con el nombre del modelo que se está creando
		 *   	- Debe estar escrito en PascalCase
		 **/
		protected function init(string $ModelName)
		{

			$this->inflector = new Inflector(new NoopWordInflector(), new NoopWordInflector());

			$this->namespace = get_namespace();

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

		private function replaceData($file)
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

        	file_put_contents($file, $content);
			
		}

// DIR PATHS


	private function setAdminRoutePath()
	{

		$this->adminRoutePath = get_path('resources/vue/router/routes/admin/routes/group');

	}

	private function setAdminViewPath()
	{

		$this->adminViewPath = get_path('resources/vue/views/admin'); 

	}

	private function setModelFormPath()
	{

		$this->modelFormPath = get_path('resources/vue/elements/forms/models'); 

	}

	private function setCreateFormPath()
	{

		$this->createFormPath = get_path('resources/vue/elements/forms/models'); 

	}

	private function setCreateViewPath()
	{

		$this->createViewPath = get_path('resources/vue/views/admin'); 

	}

	private function setCrudPath()
	{

		$this->crudPath = get_path('resources/vue/elements/cruds');

	}

	private function setEditFormPath()
	{

		$this->editFormPath = get_path('resources/vue/elements/forms/models'); 

	}

	private function setEditViewPath()
	{

		$this->editViewPath = get_path('resources/vue/views/admin'); 

	}

	private function setFilterFormPath()
	{

		$this->filterFormPath = get_path('resources/vue/elements/forms/filters');

	}

	private function setJsModelPath()
	{

		$this->jsModelPath = get_path('resources/assets/js/models');

	}

	private function setShowViewPath()
	{

		$this->showViewPath = get_path('resources/vue/views/admin'); 	

	}

// FILE PATHS

	private function setApiRouteModelFile()
	{

		$this->apiRouteModelFile = get_path('routes/api/models.php');

	}

	private function setEventServiceProviderFile()
	{

		$this->eventServiceProviderFile = get_path(app_dir_name() . '/Providers/EventServiceProvider.php');

	}

	private function setAuthServiceProviderFile()
	{

		$this->authServiceProviderFile = get_path(app_dir_name() . '/Providers/AuthServiceProvider.php');

	}

// TEMPLATE PATHS

	private function setAdminRouteTemplatePath()
	{

		$this->adminRouteTemplatePath = get_path(app_dir_name() . '/Stubs/AdminRoute');

	}

	private function setAdminViewTemplatePath()
	{

		$this->adminViewTemplatePath = get_path(app_dir_name() . '/Stubs/AdminView');

	}

	private function setCreateFormTemplatePath()
	{

		$this->createFormTemplatePath = get_path(app_dir_name() . '/Stubs/CreateForm');

	}

	private function setCreateViewTemplatePath()
	{

		$this->createViewTemplatePath = get_path(app_dir_name() . '/Stubs/CreateView');

	}

	private function setCrudTemplatePath()
	{

		$this->crudTemplatePath = get_path(app_dir_name() . '/Stubs/Crud');

	}

	private function setEditFormTemplatePath()
	{

		$this->editFormTemplatePath = get_path(app_dir_name() . '/Stubs/EditForm');

	}

	private function setEditViewTemplatePath()
	{

		$this->editViewTemplatePath = get_path(app_dir_name() . '/Stubs/EditView');

	}

	private function setFilterFormTemplatePath()
	{

		$this->filterFormTemplatePath = get_path(app_dir_name() . '/Stubs/FilterForm');

	}

	private function setJsModelTemplatePath()
	{

		$this->jsModelTemplatePath = get_path(app_dir_name() . '/Stubs/JsModel');

	}

	private function setShowViewTemplatePath()
	{

		$this->showViewTemplatePath = get_path(app_dir_name() . '/Stubs/ShowView');

	}

}