<?php

namespace Innoboxrr\LarapackGenerator\Tools\ReactView;

use Innoboxrr\LarapackGenerator\Tools\ModelView\UiModuleTool;

/**
 * El modulo React de un modelo.
 *
 * Genera exactamente la misma estructura que el de Vue, a partir del mismo
 * laraimport, y **comparte literalmente el stub del contrato**
 * (`models/<entidad>/index.js`): son funciones puras y llamadas HTTP, sin nada
 * de un framework de UI.
 *
 * Lo que cambia es solo la capa de presentacion: `<script setup>` y `v-model`
 * en Vue, componentes y `value`/`onChange` en React.
 */
class ReactViewTool extends UiModuleTool
{
	protected function framework(): string
	{
		return 'react';
	}

	protected function componentExtension(): string
	{
		return 'jsx';
	}

	protected function files(): array
	{
		return [
			// El contrato es el mismo archivo que en Vue. Si algun dia hay dos,
			// habra dos contratos.
			'ModelView/model.js' => 'index.js',

			'ReactView/store.js' => 'store/index.js',
			'ReactView/routes.js' => 'routes/index.js',
			'ReactView/forms/CreateForm.jsx' => 'forms/CreateForm.jsx',
			'ReactView/forms/EditForm.jsx' => 'forms/EditForm.jsx',
			'ReactView/forms/FilterForm.jsx' => 'forms/FilterForm.jsx',
			'ReactView/views/AdminView.jsx' => 'views/AdminView.jsx',
			'ReactView/views/CreateView.jsx' => 'views/CreateView.jsx',
			'ReactView/views/EditView.jsx' => 'views/EditView.jsx',
			'ReactView/views/ShowView.jsx' => 'views/ShowView.jsx',
			'ReactView/widgets/DataTable.jsx' => 'widgets/DataTable.jsx',
			'ReactView/widgets/ModelCard.jsx' => 'widgets/ModelCard.jsx',
			'ReactView/widgets/ModelProfile.jsx' => 'widgets/ModelProfile.jsx',
		];
	}

	protected function moduleFiles(): array
	{
		return [
			'ReactView/Module/package.json.stub' => 'package.json',
			'ReactView/Module/vite.config.js' => 'vite.config.js',
			'ReactView/Module/index.js' => 'index.js',
			'ReactView/Module/routes.js' => 'src/routes/index.js',
			'ReactView/Module/theme.js' => 'src/theme.js',
			'ReactView/Module/Breadcrumbs.jsx' => 'src/components/Breadcrumbs.jsx',
			'ReactView/Module/ActionMenu.jsx' => 'src/components/ActionMenu.jsx',
		];
	}

	protected function alwaysImported(): array
	{
		return ['TextInputComponent', 'ButtonComponent'];
	}

	protected function inputsMarker(): string
	{
		return '{/* Add more inputs */}';
	}

	protected function importLine(string $component): string
	{
		return "    {$component},\n";
	}

	protected function field(string $name, string $mode): string
	{
		return $mode === 'filter'
			? "    {$name}: null,\n"
			: "        {$name}: '',\n";
	}

	protected function submitFromForm(string $name): string
	{
		return "                {$name}: form.{$name},\n";
	}

	protected function submitFromProp(string $name): string
	{
		return "                {$name}: {$name},\n";
	}

	/**
	 * En React una prop es un parametro desestructurado con su valor por
	 * defecto; no hay bloque de definicion como en Vue.
	 */
	protected function componentProp(string $name): string
	{
		return "    {$name} = null,\n";
	}

	protected function input(array $prop, ?string $component, string $mode): string
	{
		$name = $prop['name'];
		$label = $this->label($name);

		$indent = $mode === 'filter' ? '                ' : '            ';

		$open = $mode === 'filter' ? "                <div>\n" : '';
		$close = $mode === 'filter' ? "                </div>\n" : '';

		$attrIndent = $indent . '    ';
		$tagIndent = $indent;

		if ($component === null) {
			return "{$tagIndent}{/* {$name}: declara form_component en el JSON de importacion */}\n";
		}

		$validators = $mode === 'filter' ? '' : "{$attrIndent}validators=\"required\"\n";

		$binding = "{$attrIndent}value={form.{$name} ?? ''}\n"
			. "{$attrIndent}onChange={(value) => setField('{$name}', value)}";

		// Sin customClass: la clase sale del tema del paquete, que cada
		// componente lee por su token.
		$common = "{$attrIndent}name=\"{$name}\"\n"
			. "{$attrIndent}label={t('{$label}')}\n";

		// Select y editor necesitan atributos propios; los otros comparten la
		// misma forma, asi que no hace falta enumerarlos uno a uno.
		if ($component === 'SelectInputComponent') {
			return $open
				. "{$tagIndent}<{$component}\n"
				. $common
				. $validators
				. $binding . ">\n"
				. $this->options($prop, $attrIndent)
				. "{$tagIndent}</{$component}>\n"
				. $close;
		}

		if ($component === 'EditorInputComponent') {
			return $open
				. "{$tagIndent}<{$component}\n"
				. "{$attrIndent}id={`{$name}-\${formId}`}\n"
				. $common
				. "{$attrIndent}height={300}\n"
				. $validators
				. $binding . " />\n"
				. $close;
		}

		$type = $component === 'TextInputComponent'
			? "{$attrIndent}type=\"text\"\n"
			: '';

		$placeholder = in_array($component, ['TextInputComponent', 'TextareaInputComponent'], true)
			? "{$attrIndent}placeholder={t('{$label}')}\n"
			: '';

		return $open
			. "{$tagIndent}<{$component}\n"
			. $type
			. $common
			. $placeholder
			. $validators
			. $binding . " />\n"
			. $close;
	}

	/**
	 * @param  array<string, mixed>  $prop
	 */
	private function options(array $prop, string $indent): string
	{
		if (! isset($prop['enum']) || ! is_array($prop['enum'])) {
			return "{$indent}<option value=\"\">{t('Select')}</option>\n";
		}

		$options = '';

		foreach ($prop['enum'] as $value => $label) {
			$options .= "{$indent}<option value=\"{$value}\">{t('" . $this->escape((string) $label) . "')}</option>\n";
		}

		return $options;
	}
}
