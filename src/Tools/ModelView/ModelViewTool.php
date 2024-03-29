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
	//////////////////////
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

	}

}