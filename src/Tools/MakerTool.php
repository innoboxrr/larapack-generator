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

		protected $apiRoutepath;

		protected $controllerPath;

		protected $modelPath;

		protected $modelTraitsPath;

		protected $eventsPath;

		protected $excelPath;

		protected $notificationPath;

		protected $exportPath;

		protected $filtersPath;

		protected $requestPath;

		protected $resourcePath;

		protected $policyPath;

		protected $migrationPath;

		protected $factoryPath;

		protected $testPath;

		protected $registerPath;

		///////////////////////////////////////////////////////////////////////////////////////////////////

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

		protected $controllerTemplatePath;

		protected $eventsTemplatePath;

		protected $excelTemplatePath;

		protected $exportTemplatePath;

		protected $filtersTemplatePath;

		protected $migrationTemplatePath;

		protected $factoryTemplatePath;

		protected $modelTemplatePath;

		protected $modelTraitsTemplatePath;

		protected $notificationTemplatePath;

		protected $policyTemplatePath;

		protected $requestsTemplatePath;

		protected $resourceTemplatePath;

		protected $routeTemplatePath;

		protected $testTemplatePath;

		protected $registerTemplatePath;

		///////////////////////////////////////////////////////////////////////////////////////////////////

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

	/*
	 * $ModelName: 
	 *  - Debe corresponder con el nombre del modelo que se está creando
	 *  - Debe estar escrito en PascalCase
	 */

	public function __construct(string $ModelName)
	{

		$this->inflector = new Inflector(new NoopWordInflector(), new NoopWordInflector());

		$this->namespace = get_namespace();

		$this->setModelName($ModelName);

		$this->setDirPaths();

		$this->setFilePaths();

		$this->setTemplatesPaths();

	}

	// MODEL NAME

		protected function setModelName($ModelName)
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

	// DIR PATHS

		protected function setDirPaths() 
		{

			$this->apiRoutepath = get_path('routes/api/models');

			$this->controllerPath = get_path(app_dir_name() . '/Http/Controllers');

			$this->modelPath = get_path(app_dir_name() . '/Models');

			$this->modelTraitsPath = get_path(app_dir_name() . '/Models/Traits');

			$this->eventsPath = get_path(app_dir_name() . '/Http/Events');

			$this->excelPath = get_path('resources/views/excel');

			$this->notificationPath = get_path(app_dir_name() . '/Notifications');

			$this->exportPath = get_path(app_dir_name() . '/Exports');

			$this->filtersPath = get_path(app_dir_name() . '/Models/Filters');

			$this->requestPath = get_path(app_dir_name() . '/Http/Requests');

			$this->resourcePath = get_path(app_dir_name() . '/Http/Resources/Models');

			$this->policyPath = get_path(app_dir_name() . '/Policies');

			$this->migrationPath = get_path('database/migrations');

			$this->factoryPath = get_path('database/factories');

			$this->testPath = get_path('tests/Feature/Models');

			$this->registerPath = get_path('storage/registers');

			///////////////////////////////////////////////////////////////////////////////////////////////////

			$this->adminRoutePath = get_path('resources/vue/router/routes/admin/routes/group');

			$this->adminViewPath = get_path('resources/vue/views/admin'); 

			$this->modelFormPath = get_path('resources/vue/elements/forms/models'); 
			                                                                      
			$this->createFormPath = get_path('resources/vue/elements/forms/models'); 

			$this->createViewPath = get_path('resources/vue/views/admin'); 

			$this->crudPath = get_path('resources/vue/elements/cruds');

			$this->editFormPath = get_path('resources/vue/elements/forms/models'); 

			$this->editViewPath = get_path('resources/vue/views/admin'); 

			$this->filterFormPath = get_path('resources/vue/elements/forms/filters');

			$this->jsModelPath = get_path('resources/assets/js/models');

			$this->showViewPath = get_path('resources/vue/views/admin'); 	

			// PENDIENTE: Se debe crear algo que permita publicarlos en el proyecto en cuestión
			//				Solo para el front end

		}


	// FILE PATHS

		protected function setFilePaths()
		{

			$this->apiRouteModelFile = get_path('routes/api/models.php');

			$this->eventServiceProviderFile = get_path(app_dir_name() . '/Providers/EventServiceProvider.php');

			$this->authServiceProviderFile = get_path(app_dir_name() . '/Providers/AuthServiceProvider.php');

		}

	// TEMPLATE PATHS

		protected function setTemplatesPaths()
		{

			$this->controllerTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Controller/Templates');

			$this->eventsTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Events/Templates');

			$this->excelTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Excel/Templates');

			$this->exportTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Export/Templates');

			$this->filtersTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Filters/Templates');

			$this->migrationTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Migration/Templates');

			$this->factoryTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Factory/Templates');

			$this->modelTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Model/Templates');

			$this->modelTraitsTemplatePath = get_path(app_dir_name() . '/Tools/Makers/ModelTraits/Templates');

			$this->notificationTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Notification/Templates');

			$this->policyTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Policy/Templates');

			$this->requestsTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Requests/Templates');

			$this->resourceTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Resource/Templates');

			$this->routeTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Route/Templates');

			$this->testTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Test/Templates');

			$this->registerTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Register/Templates');

			///////////////////////////////////////////////////////////////////////////////////////////////////

			$this->adminRouteTemplatePath = get_path(app_dir_name() . '/Tools/Makers/AdminRoute/Templates');

			$this->adminViewTemplatePath = get_path(app_dir_name() . '/Tools/Makers/AdminView/Templates');

			$this->createFormTemplatePath = get_path(app_dir_name() . '/Tools/Makers/CreateForm/Templates');

			$this->createViewTemplatePath = get_path(app_dir_name() . '/Tools/Makers/CreateView/Templates');

			$this->crudTemplatePath = get_path(app_dir_name() . '/Tools/Makers/Crud/Templates');

			$this->editFormTemplatePath = get_path(app_dir_name() . '/Tools/Makers/EditForm/Templates');

			$this->editViewTemplatePath = get_path(app_dir_name() . '/Tools/Makers/EditView/Templates');

			$this->filterFormTemplatePath = get_path(app_dir_name() . '/Tools/Makers/FilterForm/Templates');

			$this->jsModelTemplatePath = get_path(app_dir_name() . '/Tools/Makers/JsModel/Templates');	

			$this->showViewTemplatePath = get_path(app_dir_name() . '/Tools/Makers/ShowView/Templates');	

		}

	// Operación para reemplazar la información de muestra

		protected function replaceData($file)
		{

			$content = file_get_contents($file);

			// EL ORDEN DE REEMPLAZO SI IMPORTA

			// PRIMERO LOS PLUARALES
			 
			$content = str_replace("pluralModelName", $this->pluralModelName, $content);

        	$content = str_replace("plural_snake_case_model_name", $this->plural_snake_case_model_name, $content);

        	$content = str_replace("pluralCamelCaseModelName", $this->pluralCamelCaseModelName, $content);

        	$content = str_replace("PluralPascalCaseModelName", $this->PluralPascalCaseModelName, $content);

        	$content = str_replace("pluralkebabcasemodelname", $this->pluralkebabcasemodelname, $content);

        	$content = str_replace("pluralDotModelName", $this->pluralDotModelName, $content);

        	// EN SEGUNDO LUGAR LOS SINGULARES

        	$content = str_replace("snake_case_model_name", $this->snake_case_model_name, $content);

        	$content = str_replace("camelCaseModelName", $this->camelCaseModelName, $content);

        	$content = str_replace("PascalCaseModelName", $this->PascalCaseModelName, $content);

        	$content = str_replace("kebabcasemodelname", $this->kebabcasemodelname, $content);

        	$content = str_replace("dotModelName", $this->dotModelName, $content);

        	$content = str_replace("ModelName", $this->ModelName, $content);

        	// AL FINAL EL NOMBRE DE MODELO. (ESTE PUEDE QUE NO SE NECESITE)

        	file_put_contents($file, $content);
			
		}

}