<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

class ModelViewTool extends Tool
{

	protected $errors;

	protected $modelViewModelJsPath;
	protected $modelViewRouteJsPath;
	protected $modelViewCreateFormPath;
	protected $modelViewEditFormPath;
	protected $modelViewFilterFormPath;
	protected $modelViewAdminViewPath;
	protected $modelViewCreateViewPath;
	protected $modelViewEditViewPath;
	protected $modelViewShowViewPath;
	protected $modelViewDataTablePath;
	
	protected $modelViewTemplateModelJsPath;
	protected $modelViewTemplateRouteJsPath;
	protected $modelViewTemplateCreateFormPath;
	protected $modelViewTemplateEditFormPath;
	protected $modelViewTemplateFilterFormPath;
	protected $modelViewTemplateAdminViewPath;
	protected $modelViewTemplateCreateViewPath;
	protected $modelViewTemplateEditViewPath;
	protected $modelViewTemplateShowViewPath;
	protected $modelViewTemplateDataTablePath;
	

	# ModelJS

		// Definir la ruta de la aplicación
		private function setModelViewModelJsPath()
		{

			$this->modelViewModelJsPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName);

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateModelJsPath()
		{

			$this->modelViewTemplateModelJsPath = stubs_path('ModelView');

			return $this;

		}

		// Crear
		public function createModelViewModelJS(string $ModelName)
		{

			$this->setModelViewModelJsPath()
				->setModelViewTemplateModelJsPath();

			$modelFile = $this->modelViewModelJsPath . '/model.js';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateModelJsPath . '/model.js';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# RouteJS

		// Definir la ruta de la aplicación
		private function setModelViewRouteJsPath()
		{

			$this->modelViewRouteJsPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName);

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateRouteJsPath()
		{

			$this->modelViewTemplateRouteJsPath = stubs_path('ModelView');

			return $this;

		}

		// Crear
		public function createModelViewRouteJS(string $ModelName)
		{

			$this->setModelViewRouteJsPath()
				->setModelViewTemplateRouteJsPath();

			$modelFile = $this->modelViewRouteJsPath . '/route.js';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateRouteJsPath . '/route.js';

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

			$this->modelViewCreateFormPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/forms');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateCreateFormPath()
		{

			$this->modelViewTemplateCreateFormPath = stubs_path('ModelView/forms');

			return $this;

		}

		// Crear
		public function createModelViewCreateForm(string $ModelName)
		{

			$this->setModelViewCreateFormPath()
				->setModelViewTemplateCreateFormPath();

			$modelFile = $this->modelViewCreateFormPath . '/CreateForm.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateCreateFormPath . '/CreateForm.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Forms - EditForm

		// Definir la ruta de la aplicación
		private function setModelViewEditFormPath()
		{

			$this->modelViewEditFormPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/forms');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateEditFormPath()
		{

			$this->modelViewTemplateEditFormPath = stubs_path('ModelView/forms');

			return $this;

		}

		// Crear
		public function createModelViewEditForm(string $ModelName)
		{

			$this->setModelViewEditFormPath()
				->setModelViewTemplateEditFormPath();

			$modelFile = $this->modelViewEditFormPath . '/EditForm.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateEditFormPath . '/EditForm.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Forms - FilterForm

		// Definir la ruta de la aplicación
		private function setModelViewFilterFormPath()
		{

			$this->modelViewFilterFormPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/forms');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateFilterFormPath()
		{

			$this->modelViewTemplateFilterFormPath = stubs_path('ModelView/forms');

			return $this;

		}

		// Crear
		public function createModelViewFilterForm(string $ModelName)
		{

			$this->setModelViewFilterFormPath()
				->setModelViewTemplateFilterFormPath();

			$modelFile = $this->modelViewFilterFormPath . '/FilterForm.vue';

			if(!file_exists($modelFile)) {

				$templateFile = $this->modelViewTemplateFilterFormPath . '/FilterForm.vue';

				if(copy($templateFile, $modelFile)) {

					$this->replaceData($modelFile);

				} else {

					throw new MakerException;

				}

			}

			return $this;

		}

	# Views - AdminView

		// Definir la ruta de la aplicación
		private function setModelViewAdminViewPath()
		{

			$this->modelViewAdminViewPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateAdminViewPath()
		{

			$this->modelViewTemplateAdminViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewAdminView(string $ModelName)
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

			$this->modelViewCreateViewPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateCreateViewPath()
		{

			$this->modelViewTemplateCreateViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewCreateView(string $ModelName)
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

			$this->modelViewEditViewPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateEditViewPath()
		{

			$this->modelViewTemplateEditViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewEditView(string $ModelName)
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

			$this->modelViewShowViewPath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/views');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateShowViewPath()
		{

			$this->modelViewTemplateShowViewPath = stubs_path('ModelView/views');

			return $this;

		}

		// Crear
		public function createModelViewShowView(string $ModelName)
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

			$this->modelViewDataTablePath = get_path(app_dir_name() . '/resources/vue/app/views/admin/models/' . $this->PascalCaseModelName . '/widgets');

			return $this;

		}

		// Definir la ruta de la plantilla
		private function setModelViewTemplateDataTablePath()
		{

			$this->modelViewTemplateDataTablePath = stubs_path('ModelView/widgets');

			return $this;

		}

		// Crear
		public function createModelViewDataTable(string $ModelName)
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

	//////////////////////
	//////////////////////
	//////////////////////	

	public function create(string $ModelName)
	{

		if(app_dir_name() == 'app') {

			$this->init($ModelName)
				->createModelViewModelJS()
				->createModelViewRouteJS()
				->createModelViewCreateForm()
				->createModelViewEditForm()
				->createModelViewFilterForm()
				->createModelViewAdminView()
				->createModelViewCreateView()
				->createModelViewEditView()
				->createModelViewShowView()
				->createModelViewDataTable();

			return true;

		}

	}

}