<?php

namespace Innoboxrr\LarapackGenerator\Tools\Package;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Ecosystem;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Tools\Tool;

/**
 * Lo que un paquete necesita antes de su primer modelo: el composer.json, los
 * archivos del repositorio, la configuración de tests y la publicación.
 *
 * Todo eso se copiaba a mano de otro paquete, y por eso cada uno salía con una
 * restricción de PHP distinta, o sin ninguna, con un bump que publicaba sin
 * pasar por los tests o sin tests que correr. Aquí sale de ecosystem.json y de
 * las plantillas del ecosistema, así que un paquete recién creado pasa
 * `larapack:audit` sin un solo hallazgo.
 */
class PackageTool extends Tool
{
    /**
     * Plantilla de Stubs/Package y destino relativo a la raíz.
     */
    public const FILES = [
        'gitignore.txt' => '.gitignore',
        'gitattributes.txt' => '.gitattributes',
        'README.txt' => 'README.md',
        'CHANGELOG.txt' => 'CHANGELOG.md',
        'VERSION.txt' => 'VERSION',
        'AGENTS.txt' => 'AGENTS.md',
        'TestsWorkflow.txt' => '.github/workflows/tests.yml',
        'ReleaseWorkflow.txt' => '.github/workflows/release.yml',
        'PackageBootsTest.txt' => 'tests/Feature/PackageBootsTest.php',
        'PintTemplate.txt' => 'pint.json',
        'PhpstanTemplate.txt' => 'phpstan.neon.dist',
    ];

    private string $composerName = '';

    private string $description = '';

    /**
     * El composer.json del paquete, con las versiones de la línea base.
     *
     * Se escribe antes que nada: el resto del generador lee de él el namespace
     * y si el proyecto es un paquete o una aplicación.
     *
     * @return array<string, mixed>
     */
    public static function composerJson(string $name, string $namespace, string $description, string $license, ?Ecosystem $ecosystem = null): array
    {
        $rules = ($ecosystem ?? new Ecosystem)->rules();
        $internal = $rules['internal'] ?? [];
        $generated = $rules['generated'] ?? [];
        $namespace = trim($namespace, '\\').'\\';

        // Lo que usa el código generado: los modelos, los traits de innoboxrr;
        // los índices y filtros, search-surge; el guardado de metas, que aplana
        // los grupos anidados del formulario, support.
        $require = [
            'illuminate/support' => $rules['laravel']['illuminate'],
            'innoboxrr/search-surge' => $internal['innoboxrr/search-surge'],
            'innoboxrr/support' => $internal['innoboxrr/support'],
            'innoboxrr/traits' => $internal['innoboxrr/traits'],
        ] + ($generated['require'] ?? []);

        ksort($require);

        // larapack-generator en desarrollo es lo que le da a la CI con qué
        // auditar el paquete contra la línea base; Pint y Larastan, con qué
        // comprobar el formato y los tipos.
        $requireDev = [
            'innoboxrr/larapack-generator' => $internal['innoboxrr/larapack-generator'],
            'larastan/larastan' => $rules['dev']['larastan/larastan'],
            'laravel/pint' => $rules['dev']['laravel/pint'],
            'orchestra/testbench' => $rules['laravel']['testbench'],
            'phpunit/phpunit' => $rules['dev']['phpunit/phpunit'],
        ] + ($generated['require-dev'] ?? []);

        ksort($requireDev);

        return array_filter([
            'name' => $name,
            'description' => $description,
            'type' => 'library',
            'license' => $license,
            'require' => ['php' => $rules['php']['require']] + $require,
            'require-dev' => $requireDev,
            'suggest' => $generated['suggest'] ?? [],
            'autoload' => [
                'psr-4' => [
                    $namespace => 'src/',
                    $namespace.'Database\\Factories\\' => 'database/factories/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    $namespace.'Tests\\' => 'tests/',
                ],
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
            'config' => ['sort-packages' => true],
        ], fn ($value) => $value !== []);
    }

    public function create(string $composerName, string $description, string $license): void
    {
        $this->composerName = $composerName;
        $this->description = $description;

        $this->init('');

        foreach (self::FILES as $stub => $destination) {
            $this->scaffold(stubs_path('Package/'.$stub), $destination);
        }

        if ($license === 'MIT') {
            $this->scaffold(stubs_path('Package/LICENSE-MIT.txt'), 'LICENSE');
        }

        // La configuración compartida se versiona como .dist; phpunit.xml queda
        // para la copia local de cada uno.
        $this->scaffold(stubs_path('Test/PhpunitTemplate.txt'), 'phpunit.xml.dist');
    }

    /**
     * Escribe un archivo que desde ese momento es de quien mantiene el paquete.
     *
     * No pasa por generate(): nunca se sobrescribe, ni con --force, y no entra
     * en el manifiesto, porque que el README o un workflow cambien no es una
     * desviación de la arquitectura y larapack:verify no tiene nada que decir.
     */
    private function scaffold(string $stub, string $relative): void
    {
        if (! is_file($stub)) {
            throw MakerException::stubNotFound($stub);
        }

        $destination = root_path().'/'.$relative;

        if (file_exists($destination)) {
            Generation::record('skipped', $destination, $stub, 'ya existe');

            return;
        }

        if (Generation::isDryRun()) {
            Generation::record('create', $destination, $stub);

            return;
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw MakerException::directoryNotCreated($directory);
        }

        file_put_contents($destination, $this->replaceTokens((string) file_get_contents($stub)));

        Generation::record('create', $destination, $stub);
    }

    /**
     * Los tokens del generador más los del paquete.
     *
     * @return array<string, string>
     */
    protected function replacementMap(): array
    {
        return parent::replacementMap() + [
            '__COMPOSER_NAME__' => $this->composerName,
            '__DESCRIPTION__' => $this->description,
            '__YEAR__' => date('Y'),
            '__HOLDER__' => $this->holder(),
            '__SOURCE_DIR__' => 'src',
        ];
    }

    /**
     * El titular del copyright: el vendor, como se escribe un nombre propio.
     */
    private function holder(): string
    {
        $vendor = explode('/', $this->composerName)[0];

        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $vendor)));
    }
}
