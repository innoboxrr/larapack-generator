<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

use Innoboxrr\LarapackGenerator\Tools\Tool;

/**
 * Lo que Vue y React tienen en comun al generar el modulo de un modelo.
 *
 * Que es casi todo: la estructura de directorios, el contrato del modelo, las
 * columnas de la tabla y que campos van al formulario salen del mismo
 * laraimport y significan lo mismo en los dos frameworks. Lo unico que cambia
 * de verdad es como se escribe un input.
 *
 * `models/<entidad>/index.js` es literalmente el mismo archivo en ambos: son
 * funciones puras y llamadas HTTP, sin nada de un framework de UI. Ese es el
 * punto entero de la paridad — si cada framework tuviera su contrato, no
 * habria un contrato.
 */
abstract class UiModuleTool extends Tool
{
	/**
	 * Lo que exporta innoboxrr-form-elements, y su gemelo React con los mismos
	 * nombres. Un form_component fuera de esta lista es una errata en el JSON.
	 */
	protected const FORM_COMPONENTS = [
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
	 * `vue` o `react`. Decide el directorio de destino.
	 */
	abstract protected function framework(): string;

	/**
	 * Extension de los componentes: `vue` o `jsx`.
	 */
	abstract protected function componentExtension(): string;

	/**
	 * Plantilla (relativa a Stubs/) => destino (relativo al modulo del
	 * modelo).
	 *
	 * La ruta va completa a proposito: asi se ve en el propio codigo que Vue y
	 * React comparten literalmente el stub del contrato.
	 *
	 * @return array<string, string>
	 */
	abstract protected function files(): array;

	/**
	 * Andamiaje del modulo npm, creado una sola vez por paquete.
	 *
	 * @return array<string, string>
	 */
	abstract protected function moduleFiles(): array;

	/**
	 * Componentes que la plantilla del formulario ya importa.
	 *
	 * @return array<int, string>
	 */
	abstract protected function alwaysImported(): array;

	/**
	 * Emite el marcado de un input.
	 *
	 * @param  array<string, mixed>  $prop
	 * @param  string  $mode  create|edit|filter
	 */
	abstract protected function input(array $prop, ?string $component, string $mode): string;

	/**
	 * Emite la linea de import de un componente dentro del formulario.
	 */
	abstract protected function importLine(string $component): string;

	/**
	 * Raiz del modulo del modelo, dentro del paquete.
	 *
	 * Vive en `resources/<framework>/src/models/<modelo>`, igual que en
	 * consultant-manager y affiliate-saas, para que el backend y su UI viajen
	 * y se versionen juntos. Antes solo se generaba cuando el destino era una
	 * aplicacion, asi que en un paquete `--vue` no hacia nada.
	 */
	protected function modulePath(): string
	{
		return get_path('resources/' . $this->framework() . '/src/models/' . $this->kebabcasemodelname);
	}

	protected function packagePath(): string
	{
		return get_path('resources/' . $this->framework());
	}

	public function create(string $ModelName)
	{
		$this->init($ModelName);

		$this->scaffoldModule();

		$created = false;

		foreach ($this->files() as $stub => $destination) {
			$created = $this->generate(
				stubs_path($stub),
				$this->modulePath() . '/' . $destination
			) || $created;
		}

		return $created;
	}

	/**
	 * package.json, la configuracion de build y el agregador de rutas. Se
	 * crean con el primer modelo y no se vuelven a tocar: sin ellos el modulo
	 * generado no se puede construir ni publicar.
	 */
	protected function scaffoldModule(): void
	{
		foreach ($this->moduleFiles() as $stub => $destination) {
			$this->generate(
				stubs_path($stub),
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

		$extension = $this->componentExtension();

		foreach (['CreateForm' => 'create', 'EditForm' => 'edit', 'FilterForm' => 'filter'] as $name => $mode) {
			if (str_ends_with($file, "/forms/{$name}.{$extension}")) {
				$this->processForm($fileToProcess, $mode);

				return;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function props(): array
	{
		$model = collect(self::getJsonContent()['models'] ?? [])
			->where('name', $this->ModelName)
			->first();

		return $model['props'] ?? [];
	}

	// MODULO DEL MODELO

	/**
	 * El contrato. Identico para los dos frameworks.
	 */
	protected function processModelModule(string $file): void
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
	 * Que campos van al formulario, cuales viajan en el submit y cuales tienen
	 * que llegar desde fuera. Eso no depende del framework; lo unico que
	 * depende es como se escribe cada cosa, y eso lo ponen las subclases.
	 *
	 * @param  string  $mode  create|edit|filter
	 */
	protected function processForm(string $file, string $mode): void
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

				if ($component !== null && ! in_array($component, $this->alwaysImported(), true)) {
					$imports[$component] = true;
				}

				$fields .= $this->field($name, $mode);
			}

			if ($mode === 'filter') {
				continue;
			}

			if (! empty($prop['form_submit'])) {
				$submit .= empty($prop['form'])
					? $this->submitFromProp($name)
					: $this->submitFromForm($name);
			}

			// Lo que no se pide en el formulario pero si se envia tiene que
			// llegar desde fuera, asi que se declara como parametro de verdad.
			if (empty($prop['form']) && ! empty($prop['form_submit'])) {
				$componentProps .= $this->componentProp($name);
			}
		}

		$importLines = '';

		foreach (array_keys($imports) as $component) {
			$importLines .= $this->importLine($component);
		}

		$content = file_get_contents($file);

		foreach ($this->formMarkers($inputs, $importLines, $fields, $submit, $componentProps) as $marker => $replacement) {
			$content = str_replace($marker, $replacement, $content);
		}

		file_put_contents($file, $content);
	}

	/**
	 * @return array<string, string>
	 */
	protected function formMarkers(
		string $inputs,
		string $imports,
		string $fields,
		string $submit,
		string $componentProps
	): array {
		return [
			$this->inputsMarker() . "\n" => $inputs,
			'//import_more_components//' . "\n" => $imports,
			'//form_fields//' . "\n" => $fields,
			'//submit_data//' . "\n" => $submit,
			'//props//' . "\n" => $componentProps,
		];
	}

	abstract protected function inputsMarker(): string;

	abstract protected function field(string $name, string $mode): string;

	abstract protected function submitFromForm(string $name): string;

	abstract protected function submitFromProp(string $name): string;

	abstract protected function componentProp(string $name): string;

	/**
	 * El filtro decide por la presencia de `enum`; los formularios respetan
	 * `form_component`.
	 *
	 * @param  array<string, mixed>  $prop
	 */
	protected function componentFor(array $prop, string $mode): ?string
	{
		if ($mode === 'filter') {
			return isset($prop['enum']) ? 'SelectInputComponent' : 'TextInputComponent';
		}

		$component = $prop['form_component'] ?? null;

		return in_array($component, static::FORM_COMPONENTS, true) ? $component : null;
	}

	protected function label(string $name): string
	{
		return $this->escape(ucfirst(str_replace('_', ' ', $name)));
	}

	/**
	 * Las etiquetas acaban dentro de cadenas JS con comilla simple.
	 */
	protected function escape(string $value): string
	{
		return str_replace("'", "\\'", $value);
	}
}
