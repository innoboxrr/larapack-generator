<?php

namespace Innoboxrr\LarapackGenerator\Tools;

// Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;
use Illuminate\Support\Str;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Literal;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\StubBlocks;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;

class Tool
{
    protected static bool $fromJsonImporter = false;

    /**
     * El laraimport ya resuelto, tal como lo deja ImportDocument::toArray().
     *
     * @var array<string, mixed>
     */
    protected static array $jsonContent = [];

    // INFLECTOR
    protected Inflector $inflector;

    // NAMESPACE
    //
    // Todas las cadenas de nombres las rellena init(). Valen '' hasta entonces,
    // que es lo mismo que daba el null de antes al concatenarlas.
    protected string $namespace = '';

    protected string $dotNamespace = '';

    protected string $kebabNamespace = '';

    protected string $packageName = '';

    protected string $namespaceWithoutSeparation = '';

    protected string $lowerNamespace = '';

    protected string $slashLowerNamespace = '';

    protected string $databaseNamespace = '';

    protected string $testsNamespace = '';

    protected string $configKey = '';

    protected string $excelViewPrefix = '';

    // MODEL NAME
    protected string $ModelName = '';

    protected string $snake_case_model_name = '';

    protected string $camelCaseModelName = '';

    protected string $PascalCaseModelName = '';

    protected string $kebabcasemodelname = '';

    protected string $dotModelName = '';

    protected string $pluralModelName = '';

    protected string $plural_snake_case_model_name = '';

    protected string $pluralCamelCaseModelName = '';

    protected string $PluralPascalCaseModelName = '';

    protected string $pluralkebabcasemodelname = '';

    protected string $pluralDotModelName = '';

    protected string $SingularModelLabel = '';

    protected string $PluralModelLabel = '';

    // MANIFIESTO
    protected ?Manifest $manifest = null;

    // SET FROM JSON IMPORTER
    public static function setFromJsonImporter(bool $value): void
    {
        self::$fromJsonImporter = $value;
    }

    public static function isFromJsonImporter(): bool
    {
        return self::$fromJsonImporter;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function setJsonContent(array $content): void
    {
        self::$jsonContent = $content;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getJsonContent(): array
    {
        return self::$jsonContent;
    }

    /**
     * Las entradas de una lista del laraimport importado —`models`, `pivots`—,
     * sólo las que son objetos. Sin importación, o si no la declara, ninguna.
     *
     * Cada herramienta hacía `collect($data['models'])->where(...)` sobre lo que
     * fuera que hubiera en esa clave; aquí se lee una vez y con su tipo.
     *
     * @return list<array<mixed>>
     */
    protected static function jsonEntries(string $key): array
    {
        $entries = self::$jsonContent[$key] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * La entrada con ese nombre de una lista del laraimport, o null si no la
     * declara.
     *
     * @return array<mixed>|null
     */
    protected static function jsonEntry(string $key, string $name): ?array
    {
        return self::firstNamed(self::jsonEntries($key), $name);
    }

    /**
     * La primera entrada de una lista cuyo `name` es ése: un modelo, un
     * pivote, una request declarada. Lo que no es un objeto no cuenta.
     *
     * @return array<mixed>|null
     */
    protected static function firstNamed(mixed $entries, string $name): ?array
    {
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * El modelo que se está generando, tal como lo declara el laraimport.
     *
     * @return array<mixed>|null
     */
    protected function jsonModel(): ?array
    {
        return self::jsonEntry('models', $this->ModelName);
    }

    /**
     * La entrada que el post-proceso del importador tiene que encontrar: el
     * modelo que se está generando o el pivote que se está migrando.
     *
     * Sin ella, cada herramienta seguía con null y reventaba más abajo con un
     * TypeError que no decía qué faltaba en el laraimport.
     *
     * @param  'models'|'pivots'  $key
     * @return array<mixed>
     */
    protected static function declaredEntry(string $key, string $name): array
    {
        return self::jsonEntry($key, $name)
            ?? throw MakerException::notDeclared($key === 'models' ? 'el modelo' : 'el pivote', $name);
    }

    protected function processFileWithJson(string $fileToProcess): void {}

    // INIT

    /**
     * Lo que se acepta como nombre de modelo: un identificador, sin
     * separadores de ruta ni puntos.
     *
     * El nombre acaba en rutas de archivo —`Http/Requests/<Modelo>`,
     * `Models/Filters/<Modelo>`— y nadie lo comprobaba. `larapack:model
     * ../../x` escribía fuera del proyecto, y `larapack:remove-full-model ..`
     * borraba entero el directorio padre de cada carpeta del modelo: todos los
     * requests, todos los filtros y todos los eventos del paquete. El esquema
     * ya lo exige en el laraimport; esto lo exige también al comando suelto.
     *
     * Termina en `\z` y no en `$`: `$` también casa delante de un salto de
     * línea final, y «Post\n» pasaba y acababa en nombres de archivo y de clase.
     */
    public const MODEL_NAME = '/^[A-Za-z][A-Za-z0-9_]*\z/';

    public static function isValidModelName(string $name): bool
    {
        return preg_match(self::MODEL_NAME, $name) === 1;
    }

    /**
     * @param  string  $ModelName  El nombre del modelo que se está creando, en PascalCase.
     *                             Vacío sólo en las herramientas del paquete
     *                             (proveedores, configuración), que no nombran
     *                             ningún modelo.
     */
    protected function init(string $ModelName): static
    {
        if ($ModelName !== '' && ! self::isValidModelName($ModelName)) {
            throw MakerException::invalidModelName($ModelName);
        }

        $this->inflector = new Inflector(new NoopWordInflector, new NoopWordInflector);
        $this->namespace = get_namespace();
        $this->dotNamespace = get_dot_namespace();
        $this->kebabNamespace = get_kebab_namespace();
        // El kebab del namespace acaba en separador (innoboxrr-deals-); el
        // nombre de paquete npm no puede llevarlo.
        $this->packageName = rtrim($this->kebabNamespace, '-');
        $this->namespaceWithoutSeparation = str_replace('.', '', mb_strtolower($this->dotNamespace));
        $this->lowerNamespace = mb_strtolower($this->namespace);
        $this->slashLowerNamespace = str_replace('\\', '/', $this->lowerNamespace);
        $this->databaseNamespace = $this->outsideSourceNamespace('Database');
        $this->testsNamespace = $this->outsideSourceNamespace('Tests');
        $this->configKey = $this->configKey();
        $this->excelViewPrefix = $this->excelViewPrefix();
        $this->setModelNames($ModelName);

        return $this;
    }

    /**
     * El namespace de un directorio de la raíz que no es src/ ni app/, sin
     * separador final.
     *
     * Un paquete lo declara en su composer.json bajo su propio namespace
     * (`Acme\Shop\Database\Factories\`). Una aplicación Laravel trae el suyo sin
     * `App\` delante (`Database\Factories\`): con `App\` no lo carga nadie.
     */
    private function outsideSourceNamespace(string $directory): string
    {
        return app_dir_name() == 'src' ? $this->namespace.$directory : $directory;
    }

    /**
     * La clave de la configuración de lo generado, y el nombre de su archivo en
     * config/.
     *
     * Un paquete tiene la suya, con su nombre (`acmeshop`). En una aplicación el
     * namespace sin separación es `app`, y `config('app.*')` y `config/app.php`
     * son de Laravel: ahí la clave es `larapack`.
     */
    private function configKey(): string
    {
        return app_dir_name() == 'src' ? $this->namespaceWithoutSeparation : 'larapack';
    }

    /**
     * El prefijo de las vistas de exportación, que se generan en
     * resources/views/excel.
     *
     * Un paquete las registra con su namespace (`acmeshop::excel.`) en su
     * AppServiceProvider. Una aplicación no registra `app::`: sus vistas se
     * piden sin namespace.
     */
    private function excelViewPrefix(): string
    {
        return app_dir_name() == 'src' ? $this->namespaceWithoutSeparation.'::excel.' : 'excel.';
    }

    // MODEL NAME

    private function setModelNames(string $ModelName): void
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

        // El nombre que se lee en pantalla, y que es la clave de traducción:
        // "Audit event", no "AuditEvent". La interfaz lo pintaba con el
        // nombre de la clase, y no se podía traducir.
        $words = ucfirst(mb_strtolower(Str::headline($this->PascalCaseModelName)));
        $this->SingularModelLabel = $words;
        $this->PluralModelLabel = Pluralizer::plural($words);
    }

    // REEMPLAZAR NOMBRES

    /**
     * Tokens que los stubs usan como marcador, con su valor para el
     * modelo actual.
     *
     * @return array<string, string>
     */
    protected function replacementMap(): array
    {
        return [
            // EN PANTALLA
            'displayPropName' => Declaration::of((string) $this->ModelName)['display'],
            'SingularModelLabel' => (string) $this->SingularModelLabel,
            'PluralModelLabel' => (string) $this->PluralModelLabel,
            // LA TABLA
            //
            // Va aparte del plural en snake porque NO son lo mismo: el plural
            // es también el nombre de una variable de Blade y una clave de
            // array en las plantillas de exportación, y ésas no llevan prefijo.
            // Separarlos es lo que impide que `table_prefix` se cuele donde no
            // nombra una tabla.
            'table_name_metas' => TablePrefix::table($this->snake_case_model_name.'_metas'),
            'table_name' => TablePrefix::table($this->plural_snake_case_model_name),
            // PLURALES
            'pluralModelName' => $this->pluralModelName,
            'plural_snake_case_model_name' => $this->plural_snake_case_model_name,
            'pluralCamelCaseModelName' => $this->pluralCamelCaseModelName,
            'PluralPascalCaseModelName' => $this->PluralPascalCaseModelName,
            'pluralkebabcasemodelname' => $this->pluralkebabcasemodelname,
            'pluralDotModelName' => $this->pluralDotModelName,
            // SINGULARES
            'snake_case_model_name' => $this->snake_case_model_name,
            'camelCaseModelName' => $this->camelCaseModelName,
            'PascalCaseModelName' => $this->PascalCaseModelName,
            'kebabcasemodelname' => $this->kebabcasemodelname,
            'dotModelName' => $this->dotModelName,
            'ModelName' => $this->ModelName,
            // NAMESPACE
            // Más largos que `Namespace\`, así que strtr() los prefiere.
            'Namespace\\Database' => $this->databaseNamespace,
            'Namespace\\Tests' => $this->testsNamespace,
            'Namespace\\' => $this->namespace,
            'dotNamespace' => $this->dotNamespace,
            'kebabNamespace' => $this->kebabNamespace,
            'packageName' => $this->packageName,
            'namespaceWithoutSeparation' => $this->namespaceWithoutSeparation,
            'lowerNamespace' => $this->lowerNamespace,
            'slashLowerNamespace' => $this->slashLowerNamespace,
            // CONFIGURACIÓN Y VISTAS
            'larapackConfigKey' => $this->configKey,
            'excelViewPrefix' => $this->excelViewPrefix,
        ];
    }

    /**
     * Sustituye los tokens del stub en una sola pasada.
     *
     * strtr() con un array prueba las claves de mayor a menor longitud y
     * no vuelve a recorrer lo que ya ha sustituido. Eso hace innecesario
     * el orden manual que habia aqui (los plurales antes que los
     * singulares) e impide que un valor insertado vuelva a coincidir con
     * otro token.
     */
    protected function replaceTokens(string $content): string
    {
        return strtr($content, $this->replacementMap());
    }

    protected function replaceData(string $file): void
    {
        file_put_contents($file, $this->replaceTokens(self::contents($file)));

        // Toda copia de un stub pasa por aquí, también las que no usan
        // generate(): así se formatea todo lo generado.
        Generation::written($file);
    }

    /**
     * @param  list<string>  $providers
     */
    public function addProvidersToComposerJson(array $providers): void
    {
        $this->editComposerJson(function (array $composer) use ($providers): array {
            $extra = self::arrayAt($composer, 'extra');
            $laravel = self::arrayAt($extra, 'laravel');

            // Sin quitar repetidos, volver a ejecutar larapack:providers
            // declaraba cada proveedor otra vez y Laravel lo registraba dos.
            $laravel['providers'] = array_values(array_unique(array_merge(self::arrayAt($laravel, 'providers'), $providers)));
            $extra['laravel'] = $laravel;
            $composer['extra'] = $extra;

            return $composer;
        });
    }

    /**
     * Añade una entrada PSR-4 bajo el namespace del paquete: el primero que
     * declara `autoload.psr-4`, como siempre. Lo demás de la sección se queda
     * como estaba.
     *
     * Sin namespace no se escribe nada: `Database\Factories\` a secas, en el
     * composer.json de un paquete, choca con el de la aplicación que lo instala.
     * init() ya falla antes (get_namespace()); esto es para quien llegue aquí
     * sin pasar por él.
     *
     * @param  'autoload'|'autoload-dev'  $section
     */
    protected function addPsr4ToComposerJson(string $section, string $suffix, string $directory): void
    {
        $this->editComposerJson(function (array $composer) use ($section, $suffix, $directory): array {
            $base = (string) (array_key_first(self::arrayAt($composer, 'autoload', 'psr-4')) ?? $this->namespace);

            if ($base === '') {
                throw MakerException::missingNamespace(root_path().'/composer.json', app_dir_name());
            }

            $entries = self::arrayAt($composer, $section);
            $psr4 = self::arrayAt($entries, 'psr-4');
            $psr4[$base.$suffix] = $directory;
            $entries['psr-4'] = $psr4;
            $composer[$section] = $entries;

            return $composer;
        });
    }

    /**
     * Lee, cambia y vuelve a escribir composer.json, sólo en un paquete y nunca
     * en una simulación.
     *
     * Un composer.json que no es un objeto JSON no se toca: antes json_decode()
     * devolvía null, se le añadía la clave que tocaba y se escribía encima un
     * archivo con esa clave y nada más.
     *
     * @param  callable(array<mixed>): array<mixed>  $edit
     */
    protected function editComposerJson(callable $edit): void
    {
        if (app_dir_name() != 'src' || Generation::isDryRun()) {
            return;
        }

        file_put_contents(root_path().'/composer.json', json_encode($edit(root_composer_json()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * El array que cuelga de esas claves, o uno vacío si falta alguna o no es
     * un array.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected static function arrayAt(array $data, string ...$keys): array
    {
        foreach ($keys as $key) {
            $data = is_array($data[$key] ?? null) ? $data[$key] : [];
        }

        return $data;
    }

    /**
     * El contenido de un archivo que se va a reescribir.
     *
     * file_get_contents() suelto devuelve false si no puede leer, y ese false
     * acababa como '' en el file_put_contents() de después: el archivo se
     * reescribía vacío. Aquí no se lee, se falla (`Files::read`).
     */
    protected static function contents(string $file): string
    {
        return Files::read($file);
    }

    // GENERACION

    /**
     * Copia un stub al destino, sustituye sus tokens y, si venimos del
     * importador JSON, aplica el post-proceso con los datos del modelo.
     *
     * Este era el cuerpo que cada tool repetia en su create(), con la
     * salvedad de que un copy() fallido lanzaba una MakerException vacia:
     * ahora dice que plantilla y que destino.
     *
     * @return bool true si se escribio (o se escribiria, en dry-run).
     */
    protected function generate(string $stub, string $destination): bool
    {
        if (! is_file($stub)) {
            throw MakerException::stubNotFound($stub);
        }

        $exists = file_exists($destination);

        if ($exists && ! Generation::isForced()) {
            Generation::record('skipped', $destination, $stub, 'ya existe');

            return false;
        }

        // Con --force se regenera, pero nunca sobre algo que se haya
        // editado a mano: eso es exactamente lo que el manifiesto permite
        // distinguir. Sin esa guarda, regenerar destruiria trabajo.
        if ($exists && $this->manifest()->wasCustomised($destination)) {
            Generation::record('preserved', $destination, $stub, $this->preserve($destination) ?? 'editado a mano');

            return false;
        }

        if (Generation::isDryRun()) {
            Generation::record($exists ? 'overwrite' : 'create', $destination, $stub);

            return true;
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw MakerException::directoryNotCreated($directory);
        }

        if (! copy($stub, $destination)) {
            throw MakerException::copyFailed($stub, $destination);
        }

        // Antes que los tokens: los marcadores tienen que desaparecer
        // del archivo antes de que nada mas lo lea.
        $this->applyBlocks($destination);

        $this->replaceData($destination);

        // Después de los tokens: lo que se inserta lleva nombres de endpoint,
        // y uno como `renameModelName` no puede volver a pasar por strtr().
        $this->applyEndpoints($destination);

        if (self::isFromJsonImporter()) {
            $this->processFileWithJson($destination);
        }

        $this->manifest()->record($this->ModelName, $this->namespace, $destination, $stub);

        Generation::record($exists ? 'overwrite' : 'create', $destination, $stub);

        return true;
    }

    /**
     * Lo que el generador puede seguir manteniendo dentro de un archivo que se
     * conserva por estar editado a mano: sus propias regiones. Devuelve el
     * motivo con el que se informa, o null para el de siempre.
     */
    protected function preserve(string $destination): ?string
    {
        return null;
    }

    /**
     * La ruta de una migración de creación: la que ya exista para esa
     * tabla o, si no hay, una nueva con la hora.
     *
     * El nombre lleva la hora, así que comprobar si existía por nombre exacto
     * no la encontraba nunca: cada importación añadía otra
     * `create_<tabla>_table` y `migrate` fallaba con "table already exists".
     * Reutilizarla deja que generate() decida como con cualquier archivo:
     * omitir, regenerar con --force o conservar lo editado.
     *
     * @param  string  $name  Sin la hora: `create_products_table`.
     */
    protected function migrationFile(string $directory, string $name): string
    {
        $pattern = '/^\d{4}_\d{2}_\d{2}_\d{6}_'.preg_quote($name, '/').'\.php$/';

        $existing = array_values(array_filter(
            glob($directory.'/*_'.$name.'.php') ?: [],
            fn (string $file): bool => (bool) preg_match($pattern, basename($file))
        ));

        sort($existing);

        return $existing[0] ?? $directory.'/'.MigrationTimestamp::next().'_'.$name.'.php';
    }

    protected function manifest(): Manifest
    {
        return $this->manifest ??= new Manifest;
    }

    /**
     * La tabla del modelo actual, con el prefijo del laraimport si lo declara.
     *
     * Es lo que va en `Schema::create`, en el `$table` del modelo y en el
     * NOMBRE del archivo de migración. Lo último importa: `migrations` guarda
     * ese nombre, así que dos paquetes con un modelo `Student` dejarían dos
     * filas `create_students_table` y nadie sabría cuál es cuál.
     */
    protected function tableName(): string
    {
        return TablePrefix::table($this->plural_snake_case_model_name);
    }

    /**
     * La tabla de metas del modelo actual. Es una tabla más del paquete, así
     * que lleva el mismo prefijo: `academics_student_metas`.
     */
    protected function metasTableName(): string
    {
        return TablePrefix::table($this->snake_case_model_name.'_metas');
    }

    /**
     * `->onUpdate(...)->onDelete(...)` de una clave ajena.
     *
     * ────────────────────────────────────────────────────────────────────────
     * POR QUÉ SE ESCRIBEN SIEMPRE, AUNQUE `restrict` SEA LO QUE HARÍA LA BASE.
     *
     * Quien abra la migración tiene que poder leer la regla sin saberse de
     * memoria el valor por omisión de MySQL. Una regla que no está escrita se
     * acaba suponiendo, y la suposición cómoda —«bah, seguro que cascada»— es
     * justo la que costó siete migraciones de reparación.
     * ────────────────────────────────────────────────────────────────────────
     *
     * EL VALOR POR OMISIÓN LO DECIDE EL ESQUEMA, y es distinto según el sitio:
     * `restrict` en un modelo, `cascade` en un pivote. Aquí sólo se lee.
     *
     * @param  array<string, mixed>  $prop
     */
    protected function referentialActions(array $prop): string
    {
        $onUpdate = is_string($prop['on_update'] ?? null) ? $prop['on_update'] : 'restrict';
        $onDelete = is_string($prop['on_delete'] ?? null) ? $prop['on_delete'] : 'restrict';

        return "->onUpdate('{$onUpdate}')->onDelete('{$onDelete}')";
    }

    /**
     * Un valor del laraimport como literal PHP de comilla simple.
     *
     * Los valores libres del esquema —el `default` de una columna, un `cast`,
     * la tabla de un `constraint`— se pegaban entre comillas sin escapar. Un
     * `default` como «it's» dejaba una migración que no compila, y uno como
     * `x'); exec('...'); //` metía código en un archivo que `migrate` ejecuta.
     *
     * Lo que no necesita escape sale exactamente igual que antes —también un
     * cast con barras, como `App\Casts\Money`—, así que regenerar no cambia
     * los archivos que ya eran correctos.
     */
    protected static function phpString(string $value): string
    {
        return Literal::php($value);
    }

    /**
     * El `default` de una columna del laraimport como literal PHP, para
     * `->default(...)`. Lo usan las migraciones de modelo y las de pivote.
     *
     * Un booleano sale `true`/`false`; un número, tal cual; una cadena que se
     * escribe como un número decimal normal —`5`, `-12`, `10.00`, `0.00`—,
     * sin comillas, como siempre; cualquier otra, entre comillas y escapada.
     *
     * La cadena numérica se reconoce por su forma y no con is_numeric(), que
     * también acepta `08` (un octal inválido en PHP: la migración no
     * compilaba), `007` o `01` (los octales 7 y 1), ` 1` o `1e3`. Ésas salen
     * ahora entre comillas.
     *
     * Y no se exige que el número «valga lo mismo» leído de vuelta: `10.00`
     * sale `10.00` y no `'10.00'`, igual que en 9.0. MigrationTool::alter()
     * compara las definiciones como texto, y cambiar cómo se escribe un
     * default que no cambió le hacía escribir un `->change()` por nada.
     */
    protected static function defaultLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value) || self::isPlainNumber($value)) {
            return (string) $value;
        }

        return self::phpString((string) $value);
    }

    /**
     * Una cadena con forma de número decimal sin ceros a la izquierda, sin
     * exponente y sin espacios: `0`, `-3`, `1.50`. No `08`, `007`, `.5`,
     * `1e3` ni ` 1`.
     */
    protected static function isPlainNumber(mixed $value): bool
    {
        return is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $value) === 1;
    }

    // FORMA DECLARADA

    /**
     * Si el modelo que se esta generando tiene la accion. Un modelo del que
     * no se ha declarado nada las tiene todas.
     */
    protected function declares(string $action): bool
    {
        return Declaration::has($this->ModelName, $action);
    }

    /**
     * Un modelo sin ninguna accion no tiene controlador ni rutas: un grupo
     * `use` vacio ni siquiera compila.
     */
    protected function declaresAnyAction(): bool
    {
        return Declaration::of($this->ModelName)['actions'] !== [] || $this->endpoints() !== [];
    }

    /**
     * Los endpoints declarados del modelo o controlador que se está generando,
     * ya normalizados.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function endpoints(): array
    {
        return Declaration::of((string) $this->ModelName)['endpoints'];
    }

    /**
     * Sustituye los marcadores `//ENDPOINT_…//` del archivo por el código de
     * los endpoints declarados, con la sangría del marcador.
     *
     * Sin endpoints la línea del marcador desaparece entera, fin de línea
     * incluido: es lo que garantiza que un laraimport que no los usa genere
     * byte a byte lo mismo que antes de que existieran.
     */
    protected function applyEndpoints(string $file): void
    {
        $content = self::contents($file);

        if (! str_contains($content, '//ENDPOINT_')) {
            return;
        }

        $content = preg_replace_callback(
            '/^([ \t]*)\/\/(ENDPOINT_[A-Z_]+)\/\/\R/m',
            function (array $matches): string {
                $lines = $this->endpointCode($matches[2]);

                if ($lines === []) {
                    return '';
                }

                return implode('', array_map(
                    fn (string $line): string => ($line === '' ? '' : $matches[1].$line)."\n",
                    $lines
                ));
            },
            $content
        ) ?? throw MakerException::rewriteFailed($file);

        file_put_contents($file, $content);
    }

    /**
     * El código de un marcador de endpoints, línea a línea y sin la sangría
     * del marcador. Cada herramienta rellena los suyos.
     *
     * @return array<int, string>
     */
    protected function endpointCode(string $marker): array
    {
        return [];
    }

    /**
     * Resuelve los bloques @larapack:if del archivo recien copiado.
     *
     * Se aplica a todo lo que pasa por generate(), venga o no del
     * importador: un marcador que llegara al proyecto seria basura en el
     * codigo de alguien.
     */
    protected function applyBlocks(string $file): void
    {
        $content = self::contents($file);

        if (! str_contains($content, '@larapack:')) {
            return;
        }

        file_put_contents($file, StubBlocks::apply(
            $content,
            fn (string $condition): bool => Declaration::holds($this->ModelName, $condition)
        ));
    }

    // TOOLS

    protected function dropFile(string $file): bool
    {
        return unlink($file);
    }

    /**
     * Borra la carpeta de un modelo, si existe.
     *
     * init() deja pasar el nombre vacío porque lo usan las herramientas del
     * paquete, que no nombran ningún modelo. Al quitar, un nombre vacío
     * convierte `Http/Requests/<Modelo>` en `Http/Requests/` y se llevaba
     * todos los requests del paquete: aquí no se admite. Sólo lo frenaba
     * RemoveFullModelCommand; un remove() llamado desde otro sitio no.
     */
    protected function dropModelDir(string $path): bool
    {
        $name = (string) $this->ModelName;

        if (! self::isValidModelName($name)) {
            throw MakerException::invalidModelName($name);
        }

        return (is_dir($path) || is_link($path)) ? $this->dropDir($path) : false;
    }

    protected function dropDir(string $path): bool
    {
        // Si la carpeta misma es un enlace, se quita el enlace: recorrerlo
        // vaciaba el destino y luego rmdir() fallaba sobre el enlace.
        if (is_link($path)) {
            return unlink($path);
        }

        $files = glob($path.'/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            // GLOB_MARK pone `/` a los directorios, también a un enlace que
            // apunta a uno, y con la barra is_link() sigue el enlace.
            $file = rtrim($file, '/');

            // Un enlace se quita, no se recorre: recorrerlo borraba el
            // contenido de su destino, que está fuera del modelo.
            if (is_dir($file) && ! is_link($file)) {
                $this->dropDir($file);
            } else {
                unlink($file);
            }
        }

        return rmdir($path);
    }
}
