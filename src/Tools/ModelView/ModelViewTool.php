<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class ModelViewTool extends Tool
{
	/**
	 * Plantilla => destino, relativo al modulo del modelo.
	 */
	private const FILES = [
		'model.js' => 'index.js',
		'store.js' => 'store/index.js',
		'routes.js' => 'routes/index.js',
		'forms/CreateForm.vue' => 'forms/CreateForm.vue',
		'forms/EditForm.vue' => 'forms/EditForm.vue',
		'forms/FilterForm.vue' => 'forms/FilterForm.vue',
		'views/AdminView.vue' => 'views/AdminView.vue',
		'views/CreateView.vue' => 'views/CreateView.vue',
		'views/EditView.vue' => 'views/EditView.vue',
		'views/ShowView.vue' => 'views/ShowView.vue',
		'widgets/DataTable.vue' => 'widgets/DataTable.vue',
		'widgets/ModelCard.vue' => 'widgets/ModelCard.vue',
		'widgets/ModelProfile.vue' => 'widgets/ModelProfile.vue',
	];

	/**
	 * Andamiaje del modulo npm, creado una sola vez por paquete.
	 */
	private const MODULE_FILES = [
		'Module/package.json.stub' => 'package.json',
		'Module/vite.config.js' => 'vite.config.js',
		'Module/index.js' => 'index.js',
		'Module/routes.js' => 'src/routes/index.js',
	];

	/**
	 * Componentes de innoboxrr-form-elements que el stub ya importa.
	 */
	private const ALWAYS_IMPORTED = ['TextInputComponent', 'ButtonComponent'];

	/**
	 * Lo que exporta innoboxrr-form-elements. Un form_component fuera de esta
	 * lista es una errata en el JSON: se deja un comentario en el formulario
	 * en lugar de emitir un import que rompe el build.
	 */
	private const FORM_COMPONENTS = [
		'AvatarInputComponent',
		'CheckboxInputComponent',
		'ClickToEditComponent',
		'CodeInputComponent',
		'CodeMirrorComponent',
		'ColorPickerInputComponent',
		'CountrySelectInputComponent',
		'DynamicGroupInputComponent',
		'EditorInputComponent',
		'FileDropInputComponent',
		'FileInputComponent',
		'FqsInputComponent',
		'ModelSearchInputComponent',
		'MultiCheckboxInputComponent',
		'PolymorphicInputComponent',
		'RadioInputComponent',
		'SelectInputComponent',
		'SelectSearchInputComponent',
		'SimpleFileInputComponent',
		'SingleCheckboxInputComponent',
		'StarsInputComponent',
		'SwitchComponent',
		'TagsInputComponent',
		'TextEditorMonoStyleInputComponent',
		'TextInputComponent',
		'TextareaInputComponent',
		'TimezoneSelectInputComponent',
	];

	/**
	 * Raiz del modulo Vue del modelo.
	 *
	 * Vive dentro del paquete (`resources/vue/src/models/<modelo>`), igual que
	 * en consultant-manager y affiliate-saas, para que el backend y su UI
	 * viajen y se versionen juntos. Antes solo se generaba cuando el destino
	 * era una aplicacion, asi que en un paquete `--vue` no hacia nada.
	 */
	private function modulePath(): string
	{
		return get_path('resources/vue/src/models/' . $this->kebabcasemodelname);
	}

	private function packagePath(): string
	{
		return get_path('resources/vue');
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName);

		$this->scaffoldModule();

		$created = false;

		foreach (self::FILES as $stub => $destination) {
			$created = $this->generate(
				stubs_path('ModelView/' . $stub),
				$this->modulePath() . '/' . $destination
			) || $created;
		}

		return $created;
	}

	/**
	 * package.json, vite.config.js y el agregador de rutas del modulo. Se
	 * crean con el primer modelo y no se vuelven a tocar: sin ellos el modulo
	 * generado no se puede construir ni publicar.
	 */
	private function scaffoldModule(): void
	{
		foreach (self::MODULE_FILES as $stub => $destination) {
			$this->generate(
				stubs_path('ModelView/' . $stub),
				$this->packagePath() . '/' . $destination
			);
		}
	}

	public function remove(string $ModelName)
	{
		$this->init($ModelName);

		$path = $this->modulePath();

		return file_exists($path) ? $this->dropDir($path) : false;
	}

	/**
	 * Tool::generate() llama aqui una vez por archivo generado cuando venimos
	 * del importador; se despacha por nombre de archivo.
	 */
	protected function processFileWithJson($fileToProcess)
	{
		$file = str_replace('\\', '/', $fileToProcess);

		if (str_ends_with($file, '/models/' . $this->kebabcasemodelname . '/index.js')) {
			$this->processModelModule($fileToProcess);

			return;
		}

		foreach (['CreateForm' => 'create', 'EditForm' => 'edit', 'FilterForm' => 'filter'] as $name => $mode) {
			if (str_ends_with($file, "/forms/{$name}.vue")) {
				$this->processForm($fileToProcess, $mode);

				return;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function props(): array
	{
		$model = collect(self::getJsonContent()['models'] ?? [])
			->where('name', $this->ModelName)
			->first();

		return $model['props'] ?? [];
	}

	// MODULO DEL MODELO

	private function processModelModule(string $file): void
	{
		$columns = '';
		$sortColumn = 'id';

		foreach ($this->props() as $prop) {
			if (empty($prop['datatable'])) {
				continue;
			}

			if ($sortColumn === 'id') {
				$sortColumn = $prop['name'];
			}

			$label = $this->label($prop['name']);

			$columns .= "    {\n";
			$columns .= "        id: '{$prop['name']}',\n";
			$columns .= "        value: '{$label}',\n";
			$columns .= "        sortable: true,\n";
			$columns .= "        html: false,\n";
			$columns .= "    },\n";
		}

		$content = file_get_contents($file);

		$content = str_replace('//DATA_TABLE_COLUMNS//' . "\n", $columns, $content);

		// Se sustituye tambien la linea del valor por defecto para no acabar
		// con dos claves de ordenamiento en el mismo objeto.
		$content = str_replace(
			"//DATA_TABLE_SORT//\n    id: 'asc',",
			"    {$sortColumn}: 'asc',",
			$content
		);

		file_put_contents($file, $content);
	}

	// FORMULARIOS

	/**
	 * @param  string  $mode  create|edit|filter
	 */
	private function processForm(string $file, string $mode): void
	{
		$inputs = '';
		$fields = '';
		$submit = '';
		$componentProps = '';
		$imports = [];

		foreach ($this->props() as $prop) {
			$name = $prop['name'];

			// El filtro no ofrece el id como campo: ya lo trae el stub.
			$inForm = ! empty($prop['form']) && ! ($mode === 'filter' && $name === 'id');

			if ($inForm) {
				$component = $this->componentFor($prop, $mode);

				$inputs .= $this->input($prop, $component, $mode);

				if ($component !== null && ! in_array($component, self::ALWAYS_IMPORTED, true)) {
					$imports[$component] = true;
				}

				$fields .= $mode === 'filter'
					? "        {$name}: null,\n"
					: "        {$name}: '',\n";
			}

			if ($mode === 'filter') {
				continue;
			}

			if (! empty($prop['form_submit'])) {
				$submit .= empty($prop['form'])
					? "                {$name}: props.{$name},\n"
					: "                {$name}: form.{$name},\n";
			}

			// Lo que no se pide en el formulario pero si se envia tiene que
			// llegar desde fuera, asi que se declara como prop de verdad y no
			// como la cadena vacia que se emitia antes (invalida en Vue 3).
			if (empty($prop['form']) && ! empty($prop['form_submit'])) {
				$componentProps .= "        {$name}: {\n";
				$componentProps .= "            type: [String, Number],\n";
				$componentProps .= "            default: null,\n";
				$componentProps .= "        },\n";
			}
		}

		$importLines = '';

		foreach (array_keys($imports) as $component) {
			$importLines .= "        {$component},\n";
		}

		$content = file_get_contents($file);

		$content = str_replace('<!-- Add more inputs -->' . "\n", $inputs, $content);
		$content = str_replace('//import_more_components//' . "\n", $importLines, $content);
		$content = str_replace('//form_fields//' . "\n", $fields, $content);
		$content = str_replace('//submit_data//' . "\n", $submit, $content);
		$content = str_replace('//props//' . "\n", $componentProps, $content);

		file_put_contents($file, $content);
	}

	/**
	 * El filtro decide por la presencia de `enum`; los formularios respetan
	 * `form_component`.
	 */
	private function componentFor(array $prop, string $mode): ?string
	{
		if ($mode === 'filter') {
			return isset($prop['enum']) ? 'SelectInputComponent' : 'TextInputComponent';
		}

		$component = $prop['form_component'] ?? null;

		return in_array($component, self::FORM_COMPONENTS, true) ? $component : null;
	}

	private function input(array $prop, ?string $component, string $mode): string
	{
		$name = $prop['name'];
		$label = $this->label($name);
		$model = "form.{$name}";

		$indent = $mode === 'filter' ? '            ' : '        ';

		$open = $mode === 'filter' ? "{$indent}<div>\n" : '';
		$close = $mode === 'filter' ? "{$indent}</div>\n" : '';

		$attrIndent = $mode === 'filter' ? $indent . '    ' : $indent . '    ';
		$tagIndent = $mode === 'filter' ? $indent . '    ' : $indent;

		$validators = $mode === 'filter' ? '' : "{$attrIndent}validators=\"required\"\n";

		if ($component === null) {
			return "{$tagIndent}<!-- {$name}: declara form_component en el JSON de importacion -->\n";
		}

		$common = "{$attrIndent}:custom-class=\"inputClass\"\n"
			. "{$attrIndent}name=\"{$name}\"\n"
			. "{$attrIndent}:label=\"t('{$label}')\"\n";

		// Select y editor necesitan atributos propios; los otros 27
		// componentes de innoboxrr-form-elements comparten la misma forma, asi
		// que no hace falta enumerarlos uno a uno.
		if ($component === 'SelectInputComponent') {
			return $open
				. "{$tagIndent}<{$component}\n"
				. $common
				. $validators
				. "{$attrIndent}v-model=\"{$model}\">\n"
				. $this->options($prop, $attrIndent . '    ')
				. "{$tagIndent}</{$component}>\n"
				. $close;
		}

		if ($component === 'EditorInputComponent') {
			// El stub no define fileUploadUrl ni handleFileUploadSuccess, asi
			// que se emite sin subida de archivos en lugar de referenciar
			// variables inexistentes.
			return $open
				. "{$tagIndent}<{$component}\n"
				. "{$attrIndent}:id=\"`{$name}-\${formId}`\"\n"
				. $common
				. "{$attrIndent}:height=\"300\"\n"
				. $validators
				. "{$attrIndent}v-model=\"{$model}\" />\n"
				. $close;
		}

		$type = $component === 'TextInputComponent'
			? "{$attrIndent}type=\"text\"\n"
			: '';

		$placeholder = in_array($component, ['TextInputComponent', 'TextareaInputComponent'], true)
			? "{$attrIndent}:placeholder=\"t('{$label}')\"\n"
			: '';

		return $open
			. "{$tagIndent}<{$component}\n"
			. $type
			. $common
			. $placeholder
			. $validators
			. "{$attrIndent}v-model=\"{$model}\" />\n"
			. $close;
	}

	private function options(array $prop, string $indent): string
	{
		if (! isset($prop['enum']) || ! is_array($prop['enum'])) {
			return "{$indent}<option value=\"\">{{ t('Select') }}</option>\n";
		}

		$options = '';

		foreach ($prop['enum'] as $value => $label) {
			$options .= "{$indent}<option value=\"{$value}\">{{ t('" . $this->escape((string) $label) . "') }}</option>\n";
		}

		return $options;
	}

	private function label(string $name): string
	{
		return $this->escape(ucfirst(str_replace('_', ' ', $name)));
	}

	/**
	 * Las etiquetas acaban dentro de cadenas JS con comilla simple.
	 */
	private function escape(string $value): string
	{
		return str_replace("'", "\\'", $value);
	}
}
