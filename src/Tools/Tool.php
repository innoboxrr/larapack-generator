<?php

namespace Innoboxrr\LarapackGenerator\Tools;

// Docs: https://www.doctrine-project.org/projects/doctrine-inflector/en/2.0/index.html
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\NoopWordInflector;
use Illuminate\Support\Pluralizer;
use Illuminate\Support\Str;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\StubBlocks;

class Tool
{
    protected static $fromJsonImporter = false;

    protected static $jsonContent;

    // INFLECTOR
    protected $inflector;

    // NAMESPACE
    protected $namespace;

    protected $dotNamespace;

    protected $kebabNamespace;

    protected $packageName;

    protected $namespaceWithoutSeparation;

    protected $lowerNamespace;

    protected $slashLowerNamespace;

    protected $databaseNamespace;

    protected $testsNamespace;

    protected $configKey;

    protected $excelViewPrefix;

    // MODEL NAME
    protected $ModelName;

    protected $snake_case_model_name;

    protected $camelCaseModelName;

    protected $PascalCaseModelName;

    protected $kebabcasemodelname;

    protected $dotModelName;

    protected $pluralModelName;

    protected $plural_snake_case_model_name;

    protected $pluralCamelCaseModelName;

    protected $PluralPascalCaseModelName;

    protected $pluralkebabcasemodelname;

    protected $pluralDotModelName;

    protected $SingularModelLabel;

    protected $PluralModelLabel;

    // MANIFIESTO
    protected ?Manifest $manifest = null;

    // SET FROM JSON IMPORTER
    public static function setFromJsonImporter(bool $value)
    {
        self::$fromJsonImporter = $value;
    }

    public static function isFromJsonImporter(): bool
    {
        return self::$fromJsonImporter;
    }

    public static function setJsonContent(array $content)
    {
        self::$jsonContent = $content;
    }

    public static function getJsonContent(): array
    {
        return self::$jsonContent;
    }

    protected function processFileWithJson($fileToProcess) {}

    // INIT

    /**
     * @param  string  $ModelName  El nombre del modelo que se está creando, en PascalCase.
     */
    protected function init(string $ModelName)
    {
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

    private function setModelNames($ModelName)
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

    protected function replaceData($file)
    {
        file_put_contents($file, $this->replaceTokens(file_get_contents($file)));

        // Toda copia de un stub pasa por aquí, también las que no usan
        // generate(): así se formatea todo lo generado.
        Generation::written($file);
    }

    public function addProvidersToComposerJson(array $providers)
    {
        // Una simulacion no escribe nada, tampoco composer.json.
        if (app_dir_name() == 'src' && ! Generation::isDryRun()) {
            $composerJsonPath = root_path().'/composer.json';
            $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
            if (! isset($composerJsonData['extra']['laravel']['providers'])) {
                $composerJsonData['extra']['laravel']['providers'] = [];
            }
            // Sin quitar repetidos, volver a ejecutar larapack:providers
            // declaraba cada proveedor otra vez y Laravel lo registraba dos.
            $composerJsonData['extra']['laravel']['providers'] = array_values(array_unique(array_merge(
                $composerJsonData['extra']['laravel']['providers'],
                $providers
            )));
            file_put_contents(
                $composerJsonPath,
                json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
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
            Generation::record('preserved', $destination, $stub, 'editado a mano');

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

        if (self::isFromJsonImporter()) {
            $this->processFileWithJson($destination);
        }

        $this->manifest()->record($this->ModelName, $this->namespace, $destination, $stub);

        Generation::record($exists ? 'overwrite' : 'create', $destination, $stub);

        return true;
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
        return Declaration::of($this->ModelName)['actions'] !== [];
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
        $content = file_get_contents($file);

        if (! str_contains($content, '@larapack:')) {
            return;
        }

        file_put_contents($file, StubBlocks::apply(
            $content,
            fn (string $condition): bool => Declaration::holds($this->ModelName, $condition)
        ));
    }

    // TOOLS

    protected function dropFile(string $file)
    {
        return unlink($file);
    }

    protected function dropDir(string $path)
    {
        $files = glob($path.'/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE);
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->dropDir($file);
            } else {
                unlink($file);
            }
        }

        return rmdir($path);
    }
}
