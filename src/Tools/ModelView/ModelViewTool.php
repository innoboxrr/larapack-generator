<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

/**
 * El modulo Vue de un modelo.
 *
 * Todo lo que decide *que* se genera esta en UiModuleTool, porque sale del
 * laraimport y es igual para los dos frameworks. Aqui solo esta como se
 * escribe: `<script setup>`, `v-model` y las props de Vue 3.
 */
class ModelViewTool extends UiModuleTool
{
	protected function framework(): string
	{
		return 'vue';
	}

	protected function componentExtension(): string
	{
		return 'vue';
	}

	protected function files(): array
	{
		return [
			'ModelView/model.js' => 'index.js',
			'ModelView/store.js' => 'store/index.js',
			'ModelView/routes.js' => 'routes/index.js',
			'ModelView/forms/CreateForm.vue' => 'forms/CreateForm.vue',
			'ModelView/forms/EditForm.vue' => 'forms/EditForm.vue',
			'ModelView/forms/FilterForm.vue' => 'forms/FilterForm.vue',
			'ModelView/views/AdminView.vue' => 'views/AdminView.vue',
			'ModelView/views/CreateView.vue' => 'views/CreateView.vue',
			'ModelView/views/EditView.vue' => 'views/EditView.vue',
			'ModelView/views/ShowView.vue' => 'views/ShowView.vue',
			'ModelView/widgets/DataTable.vue' => 'widgets/DataTable.vue',
			'ModelView/widgets/ModelCard.vue' => 'widgets/ModelCard.vue',
			'ModelView/widgets/ModelProfile.vue' => 'widgets/ModelProfile.vue',
		];
	}

	protected function moduleFiles(): array
	{
		return [
			'ModelView/Module/package.json.stub' => 'package.json',
			'ModelView/Module/vite.config.js' => 'vite.config.js',
			'ModelView/Module/index.js' => 'index.js',
			'ModelView/Module/routes.js' => 'src/routes/index.js',
			'ModelView/Module/theme.js' => 'src/theme.js',
		];
	}

	protected function alwaysImported(): array
	{
		return ['TextInputComponent', 'ButtonComponent'];
	}

	protected function inputsMarker(): string
	{
		return '<!-- Add more inputs -->';
	}

	protected function importLine(string $component): string
	{
		return "        {$component},\n";
	}

	protected function field(string $name, string $mode): string
	{
		return $mode === 'filter'
			? "        {$name}: null,\n"
			: "        {$name}: '',\n";
	}

	protected function submitFromForm(string $name): string
	{
		return "                {$name}: form.{$name},\n";
	}

	protected function submitFromProp(string $name): string
	{
		return "                {$name}: props.{$name},\n";
	}

	/**
	 * Antes se emitia la cadena vacia, que no es una definicion de prop valida
	 * en Vue 3.
	 */
	protected function componentProp(string $name): string
	{
		return "        {$name}: {\n"
			. "            type: [String, Number],\n"
			. "            default: null,\n"
			. "        },\n";
	}

	protected function input(array $prop, ?string $component, string $mode): string
	{
		$name = $prop['name'];
		$label = $this->label($name);
		$model = "form.{$name}";

		$indent = $mode === 'filter' ? '            ' : '        ';

		$open = $mode === 'filter' ? "{$indent}<div>\n" : '';
		$close = $mode === 'filter' ? "{$indent}</div>\n" : '';

		$attrIndent = $indent . '    ';
		$tagIndent = $mode === 'filter' ? $indent . '    ' : $indent;

		$validators = $mode === 'filter' ? '' : "{$attrIndent}validators=\"required\"\n";

		if ($component === null) {
			return "{$tagIndent}<!-- {$name}: declara form_component en el JSON de importacion -->\n";
		}

		// Sin :custom-class: la clase sale del tema del paquete, que cada
		// componente lee por su token. Pasarla aqui obligaba a que el modulo
		// conociera el aspecto de cada control.
		$common = "{$attrIndent}name=\"{$name}\"\n"
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

	/**
	 * @param  array<string, mixed>  $prop
	 */
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
}
