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
					$sort = "{$prop['name']}: 'asc',\n";
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
							$inputs .= "<text-input-component
								:custom-class=\"inputClass\"
								type=\"text\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required length\"
								min_length=\"3\"
								max_length=\"130\"
								v-model=\"{$prop['name']}\" />\n";
							break;

						case 'SelectInputComponent':
							// Generar dinámicamente las opciones a partir del enum en el JSON
							$options = '';
							if (isset($prop['enum'])) {
								foreach ($prop['enum'] as $value => $label) {
									$options .= "<option value=\"{$value}\">{{ __('{$label}') }}</option>\n";
								}
							} else {
								// Opciones por defecto en caso de que no haya enum
								$options = "<option value=\"\">{{ __('Select') }}</option>\n";
							}

							$inputs .= "<select-input-component
								:custom-class=\"inputClass\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required\"
								v-model=\"{$prop['name']}\">
								{$options}
							</select-input-component>\n";
							break;

						case 'TextareaInputComponent':
							$inputs .= "<textarea-input-component
								:custom-class=\"inputClass\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required length\"
								min_length=\"3\"
								max_length=\"1500\"
								v-model=\"{$prop['name']}\" />\n";
							break;

						case 'EditorInputComponent':
							$inputs .= "<editor-input-component
								:id=\"`{$prop['name']}-\${formId}`\"
								:file=\"true\"
								:uploadUrl=\"fileUploadUrl\"
								:on-file-upload-success=\"handleFileUploadSuccess\"
								name=\"{$prop['name']}\"
								:height=\"300\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required\"
								v-model=\"{$prop['name']}\" />\n";
							break;

						default:
							// Si no hay componente configurado, añadir comentario
							$inputs .= "<!-- {$prop['name']} input not configured, add a component -->\n";
							break;
					}

					// Importar y registrar el componente si existe y no ha sido añadido antes
					if ($componentName && !in_array($componentName, $addedComponents)) {
						$importComponents .= "{$componentName},\n";
						$registerComponents .= "{$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}

					// Añadir al bloque de datos
					$dataFields .= "{$prop['name']}: '',\n";
				}

				// Manejo de form_submit
				if ($prop['form_submit']) {
					$submitData .= "{$prop['name']}: this.{$prop['name']},\n";
				}

				// Si form es false pero form_submit es true
				if (!$prop['form'] && $prop['form_submit']) {
					$propsData .= "{$prop['name']}: '',\n";
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
							$inputs .= "<text-input-component
								:custom-class=\"inputClass\"
								type=\"text\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required length\"
								min_length=\"3\"
								max_length=\"130\"
								v-model=\"camelCaseModelName.{$prop['name']}\" />\n";
							break;

						case 'SelectInputComponent':
							// Generar dinámicamente las opciones a partir del enum en el JSON
							$options = '';
							if (isset($prop['enum'])) {
								foreach ($prop['enum'] as $value => $label) {
									$options .= "<option value=\"{$value}\">{{ __('{$label}') }}</option>\n";
								}
							} else {
								// Opciones por defecto en caso de que no haya enum
								$options = "<option value=\"\">{{ __('Select') }}</option>\n";
							}

							$inputs .= "<select-input-component
								:custom-class=\"inputClass\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required\"
								v-model=\"camelCaseModelName.{$prop['name']}\">
								{$options}
							</select-input-component>\n";
							break;

						case 'TextareaInputComponent':
							$inputs .= "<textarea-input-component
								:custom-class=\"inputClass\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required length\"
								min_length=\"3\"
								max_length=\"1500\"
								v-model=\"camelCaseModelName.{$prop['name']}\" />\n";
							break;

						case 'EditorInputComponent':
							$inputs .= "<editor-input-component
								:id=\"`{$prop['name']}-\${formId}`\"
								:file=\"true\"
								:uploadUrl=\"fileUploadUrl\"
								:on-file-upload-success=\"handleFileUploadSuccess\"
								name=\"{$prop['name']}\"
								:height=\"300\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								validators=\"required\"
								v-model=\"camelCaseModelName.{$prop['name']}\" />\n";
							break;

						default:
							// Si no hay componente configurado, añadir comentario
							$inputs .= "<!-- {$prop['name']} input not configured, add a component -->\n";
							break;
					}

					// Importar y registrar el componente si existe y no ha sido añadido antes
					if ($componentName && !in_array($componentName, $addedComponents)) {
						$importComponents .= "{$componentName},\n";
						$registerComponents .= "{$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}

					// Añadir al bloque de datos del modelo
					$modelDataFields .= "{$prop['name']}: '',\n";
				}

				// Manejo de form_submit
				if ($prop['form_submit']) {
					$submitData .= "{$prop['name']}: this.camelCaseModelName.{$prop['name']},\n";
				}

				// Si form es false pero form_submit es true
				if (!$prop['form'] && $prop['form_submit']) {
					$propsData .= "{$prop['name']}: '',\n";
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
							$options .= "<option value=\"{$value}\">{{ __('{$label}') }}</option>\n";
						}
		
						$inputs .= "<div>
							<select-input-component
								:custom-class=\"inputClass\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								v-model=\"{$prop['name']}\">
								{$options}
							</select-input-component>
						</div>\n";
					} else {
						// Usar TextInputComponent para cualquier otro tipo
						$componentName = 'TextInputComponent';
		
						$inputs .= "<div>
							<text-input-component
								:custom-class=\"inputClass\"
								type=\"text\"
								name=\"{$prop['name']}\"
								:label=\"__('" . ucfirst($prop['name']) . "')\"
								:placeholder=\"__('" . ucfirst($prop['name']) . "')\"
								v-model=\"{$prop['name']}\" />
							</div>\n";
					}
		
					// Importar y registrar el componente si existe y no ha sido añadido antes
					if (!in_array($componentName, $addedComponents)) {
						$importComponents .= "{$componentName},\n";
						$registerComponents .= "{$componentName},\n";
						$addedComponents[] = $componentName; // Añadir el componente a la lista de los ya registrados
					}
		
					// Añadir al bloque de data
					$dataFields .= "{$prop['name']}: null,\n";
		
					// Añadir al bloque de reseteo
					$resetInputs .= "this.{$prop['name']} = null;\n";
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