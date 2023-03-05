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

			$this->modelViewModelJsPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname);

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

			$this->modelViewRouteJsPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname);

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

			$modelFile = $this->modelViewRouteJsPath . '/routes.js';

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

	# Forms - CreateForm

		// Definir la ruta de la aplicación
		private function setModelViewCreateFormPath()
		{

			$this->modelViewCreateFormPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/forms');

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

			$this->modelViewEditFormPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/forms');

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

			$this->modelViewFilterFormPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/forms');

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

			$this->modelViewAdminViewPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/views');

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

			$this->modelViewCreateViewPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/views');

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

			$this->modelViewEditViewPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/views');

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

			$this->modelViewShowViewPath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/views');

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

			$this->modelViewDataTablePath = get_path('resources/vue/app/views/admin/models/' . $this->kebabcasemodelname . '/widgets');

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