<?php

namespace Innoboxrr\LarapackGenerator\Tools\ModelView;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Support\Literal;
use Innoboxrr\LarapackGenerator\Support\Translations;
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
     * @param  bool  $required  Fuera del filtro, si el campo se exige.
     */
    abstract protected function input(array $prop, ?string $component, string $mode, bool $required = true): string;

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
        return get_path('resources/'.$this->framework().'/src/models/'.$this->kebabcasemodelname);
    }

    protected function packagePath(): string
    {
        return get_path('resources/'.$this->framework());
    }

    public function create(string $ModelName)
    {
        $this->init($ModelName);

        $this->scaffoldModule();

        $created = false;

        foreach ($this->files() as $stub => $destination) {
            foreach ($this->requiredActions($destination) as $action) {
                if (! $this->declares($action)) {
                    continue 2;
                }
            }

            $created = $this->generate(
                stubs_path($stub),
                $this->modulePath().'/'.$destination
            ) || $created;
        }

        $this->syncTranslations();

        return $created;
    }

    /**
     * El contrato JS de un `controllers[]`: una función por endpoint, sin
     * pantalla. No hay modelo, ni tabla, ni política que pregunte la interfaz,
     * así que no hay nada que pintar; quien tenga la pantalla la llama.
     *
     * Va en `src/controllers/<nombre>/index.js` y no en `src/models/`: no es
     * un modelo, y el directorio es lo primero que lee quien busca uno.
     */
    public function createController(string $name): bool
    {
        $this->init($name);

        $this->scaffoldModule();

        return $this->generate(
            stubs_path('ModelView/controller.js'),
            get_path('resources/'.$this->framework().'/src/controllers/'.$this->kebabcasemodelname).'/index.js'
        );
    }

    /**
     * Suma a src/locales las claves que usa el modulo. Se recorre todo src y no
     * solo el modelo: las rutas, las migas y los textos de la tabla tambien son
     * del modulo. Corre aunque no se haya escrito nada, para que un proyecto
     * que actualiza LaraPack reciba las claves nuevas.
     */
    protected function syncTranslations(): void
    {
        $source = $this->packagePath().'/src';

        Translations::sync(
            $source.'/locales',
            Translations::sourcesIn($source, ['js', 'jsx', 'vue']),
            Translations::FRONTEND,
            ['en', 'es']
        );
    }

    /**
     * Las acciones sin las que un archivo del modulo no tiene sentido.
     *
     * El contrato y el store valen siempre: son llamadas HTTP y estado. Las
     * vistas no. Cuelgan del indice, y el indice necesita las politicas,
     * porque la tabla las consulta para decidir que acciones ofrece. La
     * edicion, ademas, cuelga del detalle: su ruta es hija de la de show.
     *
     * @return array<int, string>
     */
    protected function requiredActions(string $destination): array
    {
        $views = ['index', 'policies'];

        return match (preg_replace('/\.(vue|jsx)$/', '', $destination)) {
            'routes/index.js', 'views/AdminView', 'widgets/DataTable', 'forms/FilterForm' => $views,
            'views/ShowView', 'widgets/ModelCard', 'widgets/ModelProfile' => [...$views, 'show'],
            'views/CreateView', 'forms/CreateForm' => [...$views, 'create'],
            'views/EditView', 'forms/EditForm' => [...$views, 'show', 'update'],
            default => [],
        };
    }

    /**
     * package.json, la configuracion de build y el agregador de rutas. Se
     * crean con el primer modelo y no se vuelven a tocar: sin ellos el modulo
     * de un paquete no se puede construir ni publicar.
     *
     * En una aplicacion el modulo no se publica: lo compila el Vite de la
     * aplicacion, que ya tiene su package.json y su vite.config.js. Un segundo
     * par dentro de resources/ no lo usaba nadie y confundia a quien lo leia.
     */
    protected function scaffoldModule(): void
    {
        $inApplication = app_dir_name() === 'app';

        foreach ($this->moduleFiles() as $stub => $destination) {
            if ($inApplication && in_array($destination, ['package.json', 'vite.config.js'], true)) {
                continue;
            }

            $this->generate(
                stubs_path($stub),
                $this->packagePath().'/'.$destination
            );
        }
    }

    public function remove(string $ModelName)
    {
        $this->init($ModelName);

        $path = $this->modulePath();

        return $this->dropModelDir($path);
    }

    /**
     * Tool::generate() llama aqui una vez por archivo generado cuando venimos
     * del importador; se despacha por nombre de archivo.
     */
    protected function processFileWithJson($fileToProcess)
    {
        $file = str_replace('\\', '/', $fileToProcess);

        if (str_ends_with($file, '/models/'.$this->kebabcasemodelname.'/index.js')) {
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

    /**
     * Las metas que el formulario puede escribir: las editables que no son
     * protegidas, de un modelo con metas, y que no se llaman como una columna.
     *
     * @return array<int, string>
     */
    protected function editableMetas(): array
    {
        $model = collect(self::getJsonContent()['models'] ?? [])
            ->where('name', $this->ModelName)
            ->first() ?? [];

        if (empty($model['metas'])) {
            return [];
        }

        return array_values(array_diff(
            $model['editable_metas'] ?? [],
            $model['protected_metas'] ?? [],
            array_column($model['props'] ?? [], 'name')
        ));
    }

    // MODULO DEL MODELO

    /**
     * El contrato. Identico para los dos frameworks.
     */
    protected function processModelModule(string $file): void
    {
        $columns = '';
        $bulkUpdates = '';
        $sortColumn = 'id';

        $declaration = Declaration::of((string) $this->ModelName);
        $bulkUpdate = $this->declares('bulkUpdate');

        foreach ($this->props() as $prop) {
            $enum = ! empty($prop['enum']) && is_array($prop['enum']) ? $prop['enum'] : null;

            if ($bulkUpdate && $enum !== null && ! empty($prop['updatable']) && ! in_array($prop['name'], $declaration['secret'], true)) {
                $bulkUpdates .= $this->bulkUpdateActions($prop['name'], $enum);
            }

            if (empty($prop['datatable'])) {
                continue;
            }

            if ($sortColumn === 'id') {
                $sortColumn = $prop['name'];
            }

            $label = $this->label($prop['name']);

            $columns .= "    {\n";
            $columns .= "        id: '{$prop['name']}',\n";
            $columns .= "        value: t('{$label}'),\n";
            $columns .= "        sortable: true,\n";
            $columns .= "        html: false,\n";

            // La tabla enseñaba el valor guardado (`published`) en lugar de la
            // etiqueta que el formulario ya usaba para el mismo campo.
            if ($enum !== null) {
                $columns .= '        parser: (value) => ({ '.$this->enumLabels($enum)." })[value] ?? value,\n";
            } elseif ($bulkUpdate && $this->editsInline($prop, $declaration['secret'])) {
                // Se edita en su celda y se guarda sólo ese campo.
                $columns .= "        component: 'ClickToEdit',\n";
                $columns .= "        parser: (value, row) => ({ value, label: t('{$label}'), save: (next) => updateField(row.id, '{$prop['name']}', next) }),\n";
            }

            $columns .= "    },\n";
        }

        $content = file_get_contents($file);

        $content = str_replace('//DATA_TABLE_COLUMNS//'."\n", $columns, $content);

        $content = str_replace('//BULK_UPDATE_ACTIONS//'."\n", $bulkUpdates, $content);

        // Se sustituye tambien la linea del valor por defecto para no acabar
        // con dos claves de ordenamiento en el mismo objeto.
        $content = str_replace(
            "//DATA_TABLE_SORT//\n    id: 'asc',",
            "    {$sortColumn}: 'asc',",
            $content
        );

        file_put_contents($file, $content);
    }

    // ENDPOINTS DECLARADOS

    /**
     * Lo que el contrato JS lleva de los endpoints declarados (SPEC 02): una
     * función por endpoint, los de colección en la barra de la tabla y los de
     * registro en `recordActions()`, que lee la ficha. La tabla recibe los de
     * registro del Resource, en `actions` de cada fila.
     *
     * Todo lo que viene del laraimport pasa por escape(), aunque la validación
     * ya lo limite a camelCase y segmentos kebab: el escape no puede depender
     * de que otra pieza haya hecho su trabajo.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        $endpoints = $this->endpoints();

        return match ($marker) {
            'ENDPOINT_COLLECTION_ACTIONS' => $this->collectionActions($endpoints),
            'ENDPOINT_RECORD_ACTIONS' => $this->recordActions($endpoints),
            'ENDPOINT_SERVICES' => array_merge([], ...array_map(
                fn (array $endpoint): array => $this->endpointService($endpoint),
                $endpoints
            )),
            default => [],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<int, string>
     */
    private function collectionActions(array $endpoints): array
    {
        $lines = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['target'] !== 'collection' || ! Endpoints::offersInterface($endpoint)) {
                continue;
            }

            $lines = [
                ...$lines,
                '{',
                ...$this->actionFields($endpoint),
                '    route: false,',
                '    policy: false,',
                '    params: {},',
                '},',
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<int, string>
     */
    private function recordActions(array $endpoints): array
    {
        $entries = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['target'] !== 'record' || ! Endpoints::offersInterface($endpoint)) {
                continue;
            }

            $entries = [
                ...$entries,
                '    {',
                ...array_map(fn (string $line): string => '    '.$line, $this->actionFields($endpoint)),
                '    },',
            ];
        }

        if ($entries === []) {
            return [];
        }

        return [
            '',
            '/**',
            ' * Los endpoints declarados que se lanzan sobre un registro, para el menú',
            ' * de la ficha. La tabla recibe los mismos del Resource, en `actions` de',
            ' * cada fila. El `id` es el nombre del endpoint: es la clave con la que',
            ' * responde `policies`, y la acción se ofrece si la política lo permite.',
            ' */',
            'export const recordActions = () => [',
            ...$entries,
            ']',
        ];
    }

    /**
     * Lo que comparten la acción de la barra y la de la ficha.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<int, string>
     */
    private function actionFields(array $endpoint): array
    {
        $danger = $endpoint['method'] === 'delete';

        return [
            "    id: '".$this->escape((string) $endpoint['name'])."',",
            "    name: t('".$this->escape(Endpoints::label((string) $endpoint['name']))."'),",
            "    success: t('The action was completed.'),",
            "    callback: '".$this->escape(Endpoints::jsFunction($endpoint))."',",
            '    icon: '.($danger ? "'delete'" : "'play'").',',
            ...($danger ? ['    danger: true,'] : []),
        ];
    }

    /**
     * La función del contrato que llama a un endpoint por su nombre de ruta,
     * como las CRUD: el verbo declarado, el token CSRF sólo en lo que escribe
     * y los reintentos sólo en lo que lee.
     *
     * Recibe un solo objeto, como `deleteModel(data)`, porque es lo que le
     * pasa la tabla: `data.id` es el registro, los parámetros de la URI se
     * sacan de `data` por su nombre y lo demás viaja en el cuerpo (o en la
     * query, si es `get`). La de un modelo que escribe pregunta antes, como
     * borrar; `{ confirm: false }` la llama sin diálogo.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<int, string>
     */
    private function endpointService(array $endpoint): array
    {
        $verb = (string) $endpoint['method'];
        $read = $verb === 'get';
        $record = $endpoint['target'] === 'record';
        $confirm = $endpoint['target'] !== null && ! $read;
        $id = $this->snake_case_model_name.'_id';
        $parameters = Endpoints::parameters((string) $endpoint['uri']);

        $doc = [
            '',
            '/**',
            ' * `'.$this->escape((string) $endpoint['name']).'`: '.strtoupper($verb).' '.$this->escape((string) $endpoint['uri']).', declarado en el laraimport.',
        ];

        if ($record) {
            array_push($doc, ' *', ' * `data.id` es el registro; lo demás viaja '.($read ? 'en la query.' : 'en el cuerpo.'));
        }

        if ($confirm) {
            array_push($doc, ' *', ' * Pregunta antes, como borrar: `{ confirm: false }` la llama sin diálogo.');
        }

        $doc[] = ' */';

        $body = [];
        $source = 'data';

        if ($record) {
            $body[] = '    const { id, ...payload } = data';
            $source = 'payload';
        } elseif ($parameters !== []) {
            $body[] = '    const payload = { ...data }';
            $source = 'payload';
        }

        $routeArguments = '';

        if ($parameters !== []) {
            $pairs = [];
            $removed = [];

            foreach ($parameters as $parameter) {
                if ($record && $parameter === $id) {
                    $pairs[] = "{$parameter}: id";

                    continue;
                }

                // El parámetro va en la URI y no también en el cuerpo.
                $pairs[] = "{$parameter}: payload.{$parameter}";
                $removed[] = "    delete payload.{$parameter}";
            }

            $body[] = '    const parameters = { '.implode(', ', $pairs).' }';
            array_push($body, ...$removed);
            $routeArguments = ', parameters';
        }

        if ($body !== []) {
            $body[] = '';
        }

        if ($confirm) {
            $body = [
                ...$body,
                '    if (confirm) {',
                '        await confirmOrCancel({',
                "            title: t('".$this->escape(Endpoints::label((string) $endpoint['name']))."'),",
                "            message: t('Are you sure you want to continue?'),",
                "            confirmLabel: t('Yes, continue'),",
                "            cancelLabel: t('Cancel'),",
                ...($verb === 'delete' ? ["            variant: 'danger',"] : []),
                '        })',
                '    }',
                '',
            ];
        }

        $payload = $read ? [] : ['        _token: csrfToken(),'];
        $payload[] = "        ...{$source},";

        if ($record && ! in_array($id, $parameters, true)) {
            $payload[] = "        {$id}: id,";
        }

        return [
            ...$doc,
            'export const '.$this->escape(Endpoints::jsFunction($endpoint)).' = '
                .($confirm ? 'async (data = {}, { confirm = true } = {}) => {' : '(data = {}) => {'),
            ...$body,
            "    return makeHttpRequest('{$verb}', route(API_ROUTE_PREFIX + '".$this->escape((string) $endpoint['route'])."'{$routeArguments}), {",
            ...$payload,
            '    }, {}, '.($read ? '3' : '0').', 1500)',
            '}',
        ];
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

        // Las metas que escribe el formulario. Viajan planas, como las espera
        // updateModelMetas(), no son obligatorias —vacía, una meta se borra— y
        // al editar se rellenan desde payload.
        if ($mode !== 'filter') {
            foreach ($this->editableMetas() as $key) {
                $inputs .= $this->input(['name' => $key], 'TextInputComponent', $mode, false);
                $fields .= $this->field($key, $mode);
                $submit .= $this->submitFromForm($key);
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
            $this->inputsMarker()."\n" => $inputs,
            '//import_more_components//'."\n" => $imports,
            '//form_fields//'."\n" => $fields,
            '//submit_data//'."\n" => $submit,
            '//props//'."\n" => $componentProps,
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

    /**
     * `'draft': t('Draft'), 'published': t('Published')`
     *
     * @param  array<string|int, string>  $enum
     */
    /**
     * Una columna que se edita en su celda: texto de una línea que el
     * formulario ya edita con un campo de texto. El texto largo no cabe en una
     * celda, un enum tiene sus acciones masivas y un secreto no sale por la API.
     *
     * @param  array<string, mixed>  $prop
     * @param  array<int, string>  $secret
     */
    protected function editsInline(array $prop, array $secret): bool
    {
        return ! empty($prop['updatable'])
            && ! empty($prop['form'])
            && in_array($prop['type'] ?? null, ['string', 'char'], true)
            && ($prop['form_component'] ?? 'TextInputComponent') === 'TextInputComponent'
            && ! in_array($prop['name'], $secret, true);
    }

    /**
     * Una acción masiva por cada valor del enum: "Estado: Publicado" pone ese
     * valor en todos los seleccionados.
     *
     * @param  array<string|int, string>  $enum
     */
    protected function bulkUpdateActions(string $name, array $enum): string
    {
        $field = $this->label($name);
        $actions = '';

        foreach ($enum as $value => $label) {
            $value = $this->escape((string) $value);

            $actions .= "    {\n";
            $actions .= "        id: '{$name}-{$value}',\n";
            $actions .= "        name: t('{$field}') + ': ' + t('".$this->escape((string) $label)."'),\n";
            $actions .= "        success: t('Records updated'),\n";
            $actions .= "        callback: 'bulkUpdateModels',\n";
            $actions .= "        icon: 'edit',\n";
            $actions .= "        params: { {$name}: '{$value}' },\n";
            $actions .= "    },\n";
        }

        return $actions;
    }

    protected function enumLabels(array $enum): string
    {
        $pairs = [];

        foreach ($enum as $value => $label) {
            $pairs[] = "'".$this->escape((string) $value)."': t('".$this->escape((string) $label)."')";
        }

        return implode(', ', $pairs);
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
        return Literal::js($value);
    }

    /**
     * Una etiqueta dentro de `{{ t('…') }}` en una plantilla Vue: además del
     * escape JS, ni entidades ni `}}` (Literal::vueInterpolation()).
     */
    protected function interpolation(string $value): string
    {
        return Literal::vueInterpolation($value);
    }

    /**
     * Un valor del laraimport dentro de un atributo HTML o JSX entre comillas
     * dobles: el `value` de una opción.
     */
    protected function attribute(string $value): string
    {
        return Literal::htmlAttribute($value);
    }
}
