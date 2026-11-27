<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\CiGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Las piezas de `larapack:ci` una a una: lo que se lee del proyecto, lo que se
 * escribe y lo que se imprime para que lo pegue el dueño.
 *
 * CiCommandTest prueba la orden entera; aquí se fija cada decisión por
 * separado, con los casos que la orden no llega a enseñar: un workflow que no
 * es del proyecto, un lock a medio escribir, una rama con caracteres de shell.
 *
 * Todo corre con los avisos convertidos en excepciones, como bajo Artisan: un
 * `strtolower(null)` que aquí sólo sería ruido allí tumba la orden.
 */
final class CiGateTest extends TestCase
{
    private int $errorReporting;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-ci-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        // Todos los avisos, como Laravel (error_reporting(-1)): la
        // configuración de PHP de cada máquina no decide qué se ve.
        $this->errorReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $level, string $message): bool {
            // Lo silenciado con @ es parte del código probado, no un aviso.
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        error_reporting($this->errorReporting);
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove("{$path}/{$entry}");
                }
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function put(string $relative, string $contents): void
    {
        $file = $this->root.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function composer(array $composer, ?array $lock = null): void
    {
        $this->put('composer.json', (string) json_encode($composer));

        if ($lock !== null) {
            $this->put('composer.lock', (string) json_encode($lock));
        }
    }

    private function git(string ...$arguments): string
    {
        exec('git -C '.escapeshellarg($this->root).' -c init.defaultBranch=main '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        return implode("\n", $output);
    }

    // EL WORKFLOW

    public function test_el_workflow_de_un_paquete(): void
    {
        $this->composer(['require' => ['php' => '^8.2']]);
        // Un .env.example en un paquete no es una aplicación que arrancar.
        $this->put('.env.example', "APP_KEY=\n");

        $this->assertFalse(CiGate::isApplication($this->root));
        $this->assertSame(<<<'YAML'
        name: LaraPack

        # Generado por `larapack:ci`. El contrato de LaraPack como puerta de la rama:
        # la protección de la rama exige el check «LaraPack verify», que es el nombre
        # del job. Si se cambia, la protección espera un check que ya no llega.
        # --strict cuenta los avisos como fallos: una excepción #[HandRolled] sin
        # aprobar en .larapack/hand-rolled.lock no entra.
        on:
            push:
                branches: [main, master]
            pull_request:

        jobs:
            verify:
                name: LaraPack verify
                runs-on: ubuntu-latest

                steps:
                    - uses: actions/checkout@v5

                    - uses: shivammathur/setup-php@v2
                      with:
                          # La mínima que admite require.php en composer.json.
                          php-version: '8.2'
                          coverage: none

                    - name: Instalar dependencias
                      run: composer update --prefer-dist --no-interaction --no-progress

                    - name: Contrato de LaraPack
                      run: php vendor/bin/builder larapack:verify --strict --format=json

        YAML, CiGate::workflow($this->root, null));
    }

    public function test_el_workflow_de_una_aplicacion(): void
    {
        $this->composer(
            ['require' => ['php' => '^8.2', 'ext-Intl' => '*'], 'require-dev' => ['ext-intl' => '*', 'ext-gd' => '*']],
            ['packages' => [['name' => 'symfony/console', 'require' => ['php' => '>=8.4']]]]
        );
        $this->put('artisan', "<?php\n");
        $this->put('.env.example', "APP_KEY=\n");

        $this->assertTrue(CiGate::isApplication($this->root.'/'));
        $this->assertSame(<<<'YAML'
        name: LaraPack

        # Generado por `larapack:ci`. El contrato de LaraPack como puerta de la rama:
        # la protección de la rama exige el check «LaraPack verify», que es el nombre
        # del job. Si se cambia, la protección espera un check que ya no llega.
        # --strict cuenta los avisos como fallos: una excepción #[HandRolled] sin
        # aprobar en .larapack/hand-rolled.lock no entra.
        on:
            push:
                branches: [release/2.x]
            pull_request:

        jobs:
            verify:
                name: LaraPack verify
                runs-on: [self-hosted, linux]

                steps:
                    - uses: actions/checkout@v5

                    - uses: shivammathur/setup-php@v2
                      with:
                          # La mínima con la que se instala composer.lock: algún paquete bloqueado la exige.
                          php-version: '8.4'
                          extensions: gd, intl
                          coverage: none

                    # Artisan arranca la aplicación; verify no toca la base ni usa APP_KEY.
                    - name: Preparar .env
                      run: test -f .env || cp .env.example .env

                    - name: Instalar dependencias
                      run: composer install --prefer-dist --no-interaction --no-progress

                    - name: Contrato de LaraPack
                      run: php artisan larapack:verify --strict --format=json

        YAML, CiGate::workflow($this->root, 'release/2.x', ['self-hosted', 'linux']));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ramas(): array
    {
        return [
            'una rama normal va tal cual' => ['feature/x-1.2_b', '[feature/x-1.2_b]'],
            'con espacio, entre comillas' => ['main x', "['main x']"],
            'con algo delante que no es de YAML' => ['@main', "['@main']"],
            'una comilla se dobla' => ["it's", "['it''s']"],
        ];
    }

    #[DataProvider('ramas')]
    public function test_la_rama_se_escribe_en_yaml_sin_romperlo(string $branch, string $yaml): void
    {
        $this->composer(['require' => ['php' => '^8.3']]);

        $this->assertStringContainsString("        branches: {$yaml}\n", CiGate::workflow($this->root, $branch));
    }

    // RUNS-ON

    public function test_runs_on_limpia_quita_vacios_y_repetidos(): void
    {
        $this->assertSame(['self-hosted', 'linux'], CiGate::runsOn(['', ' self-hosted ', 'self-hosted', 'linux']));
        $this->assertSame(['a', 'b'], CiGate::runsOn(['a', 'a', 'b']));
        $this->assertSame(['a', 'b'], CiGate::runsOn(['', ' ["a","b","a"] ']));
        $this->assertSame([], CiGate::runsOn([' ', '']));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function runsOnInvalidos(): array
    {
        $list = '--runs-on con [ tiene que ser una lista JSON de etiquetas, como \'["self-hosted","linux"]\'.';

        return [
            'JSON roto' => [['["a",'], $list],
            'lista vacía' => [['[]'], $list],
            'una etiqueta con espacio' => [['self hosted'], '--runs-on: «self hosted» no es una etiqueta de runner (letras, dígitos, ., _ y -).'],
            'un número en la lista' => [['[5]'], '--runs-on: «5» no es una etiqueta de runner (letras, dígitos, ., _ y -).'],
            'una expresión' => [['${{ matrix.os }}'], '--runs-on: «${{ matrix.os }}» no es una etiqueta de runner (letras, dígitos, ., _ y -).'],
        ];
    }

    /**
     * @param  list<string>  $values
     */
    #[DataProvider('runsOnInvalidos')]
    public function test_runs_on_rechaza_lo_que_no_son_etiquetas(array $values, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        CiGate::runsOn($values);
    }

    public function test_las_etiquetas_de_un_runs_on_escrito(): void
    {
        $this->assertSame(['self-hosted', 'linux'], CiGate::labelsOf('[self-hosted, linux]'));
        $this->assertSame(['a', 'b'], CiGate::labelsOf('[ "a" , \'b\' ]'));
        $this->assertSame(['ubuntu-latest'], CiGate::labelsOf('ubuntu-latest'));
        $this->assertNull(CiGate::labelsOf(''));
        $this->assertNull(CiGate::labelsOf('${{ matrix.os }}'));
        // Los corchetes tienen que envolver el valor entero.
        $this->assertNull(CiGate::labelsOf('x[a]'));
        $this->assertNull(CiGate::labelsOf('[a]x'));
    }

    public function test_los_runners_de_los_otros_workflows(): void
    {
        // El de LaraPack se ignora, esté donde esté en el orden.
        $this->put('.github/workflows/larapack.yml', "jobs:\n  v:\n    runs-on: ubuntu-latest\n");
        $this->put('.github/workflows/b.yml', implode("\n", [
            'jobs:',
            '  one:',
            '    runs-on:   [self-hosted, linux]   # los nuestros',
            '  two:',
            '    runs-on: [self-hosted, linux]',
            '  three:',
            '    runs-on:',
            '      - self-hosted',
            "      - 'gpu' # comentario",
            '    name: build - x',
            '  empty:',
            '    runs-on:',
            '    steps: []',
            '  # runs-on: comentado',
            '    foo-runs-on: no',
        ])."\n");
        $this->put('.github/workflows/a.yaml', "jobs:\n  x:\n    runs-on: [self-hosted, linux]\n");
        $this->put('.github/workflows/z.yml', "jobs:\n  x:\n    runs-on: macos-14\n");

        $this->assertSame([
            '[self-hosted, linux]' => ['a.yaml', 'b.yml'],
            '[self-hosted, gpu]' => ['b.yml'],
            'macos-14' => ['z.yml'],
        ], CiGate::otherRunners($this->root));
    }

    // LA VERSIÓN DE PHP

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: string}>
     */
    public static function versiones(): array
    {
        $lock = fn (array $packages, array $dev = []): array => ['packages' => $packages, 'packages-dev' => $dev];
        $needs = fn (string $php): array => ['name' => 'x/y', 'require' => ['php' => $php]];

        return [
            'la de la plataforma, con espacios' => [['config' => ['platform' => ['php' => ' 8.2.1 ']], 'require' => ['php' => '^8.4']], null, '8.2'],
            'la de la plataforma con v' => [['config' => ['platform' => ['php' => 'v8.1']]], null, '8.1'],
            'una plataforma que no empieza por la versión no se adivina' => [['config' => ['platform' => ['php' => 'latest-7.4']], 'require' => ['php' => '^8.2']], null, '8.2'],
            'la del require sin lock' => [['require' => ['php' => '>=8.1 <9']], null, '8.1'],
            'la mayor del lock, en cualquier orden' => [['require' => ['php' => '^8.1']], $lock([$needs('>=8.2'), $needs('>=8.4'), $needs('>=8.3')]), '8.4'],
            'también la de packages-dev' => [['require' => ['php' => '^8.1']], $lock([$needs('>=8.2')], [$needs('^8.3')]), '8.3'],
            'el require si el lock pide menos' => [['require' => ['php' => '^8.4']], $lock([$needs('>=8.2')]), '8.4'],
            'el lock sin require' => [[], $lock([$needs('>=8.2')]), '8.2'],
            'sin nada, la línea base' => [[], null, '8.3'],
            'un require sin cota es como no tenerlo' => [['require' => ['php' => '*']], null, '8.3'],
            'un require en blanco también' => [['require' => ['php' => '  ']], null, '8.3'],
            'un require ilegible también' => [['require' => ['php' => 'no es una versión']], null, '8.3'],
            'un lock a medio escribir no tumba nada' => [['require' => ['php' => '^8.2']], ['packages' => 'roto', 'packages-dev' => 'roto'], '8.2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>|null  $lock
     */
    #[DataProvider('versiones')]
    public function test_la_version_de_php_del_job(array $composer, ?array $lock, string $expected): void
    {
        $this->composer($composer, $lock);

        $this->assertSame($expected, CiGate::phpVersion($this->root)[0]);
    }

    public function test_las_extensiones_declaradas(): void
    {
        $this->composer([
            'require' => ['php' => '^8.3', 'ext-Intl' => '*', 'acme/ext-tools' => '^1.0', 'ext-zend-opcache' => '*', 'ext-gd' => '*'],
            'require-dev' => ['ext-pdo_sqlite' => '*', 'ext-GD' => '*'],
        ]);

        // `acme/ext-tools` es un paquete; `ext-zend-opcache` no se convierte
        // en una extensión `zend` que no existe.
        $this->assertSame(['gd', 'intl', 'pdo_sqlite'], CiGate::extensions($this->root));
    }

    // GIT

    public function test_lo_que_se_lee_de_git(): void
    {
        $this->assertNull(CiGate::gitTop($this->root));
        $this->assertNull(CiGate::defaultBranch($this->root));
        $this->assertNull(CiGate::repository($this->root));

        $this->git('init', '-q');
        mkdir($this->root.'/sub');

        $this->assertSame(str_replace('\\', '/', (string) realpath($this->root)), CiGate::gitTop($this->root.'/sub'));
        $this->assertNull(CiGate::defaultBranch($this->root));
        $this->assertNull(CiGate::repository($this->root));

        $this->git('remote', 'add', 'origin', 'git@github.com:Acme/my.repo.git');
        $this->git('symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/trunk');

        $this->assertSame('trunk', CiGate::defaultBranch($this->root));
        $this->assertSame('Acme/my.repo', CiGate::repository($this->root));

        $this->git('remote', 'set-url', 'origin', 'https://github.com/acme/app/');
        $this->assertSame('acme/app', CiGate::repository($this->root));

        $this->git('remote', 'set-url', 'origin', 'https://gitlab.com/acme/app.git');
        $this->assertNull(CiGate::repository($this->root));
    }

    // CODEOWNERS

    public function test_quien_es_un_dueno(): void
    {
        foreach (['@octocat', '@acme/platform-team', '@a', 'dueno@empresa.com'] as $owner) {
            $this->assertTrue(CiGate::isOwner($owner), $owner);
        }

        foreach (['octocat', 'x@octocat!', '@octocat!', '@-octocat', '@octocat- ', 'dueno@empresa.com otra', 'a@b', '@acme/team extra'] as $owner) {
            $this->assertFalse(CiGate::isOwner($owner), $owner);
        }
    }

    public function test_el_codeowners_que_lee_github(): void
    {
        $this->assertSame('.github/CODEOWNERS', CiGate::codeownersFile($this->root));

        $this->put('docs/CODEOWNERS', '');
        $this->assertSame('docs/CODEOWNERS', CiGate::codeownersFile($this->root));

        $this->put('CODEOWNERS', '');
        $this->assertSame('CODEOWNERS', CiGate::codeownersFile($this->root));

        $this->assertSame(
            ['/.larapack/hand-rolled.lock', '/.github/workflows/larapack.yml', '/CODEOWNERS'],
            CiGate::ownedPaths('CODEOWNERS')
        );
    }

    public function test_fusionar_codeowners(): void
    {
        $current = implode("\n", [
            '# del proyecto',
            '   ',
            '  /.larapack/hand-rolled.lock   @Owner @Other   # sangrado y con comentario',
            '/.github/workflows/larapack.yml @someone',
            '.github/workflows/larapack.yml @owner',
            '/CODEOWNERS @someone-else',
        ]);

        $merged = CiGate::mergeCodeowners($current, [
            '/.larapack/hand-rolled.lock', '/.github/workflows/larapack.yml', '/CODEOWNERS', '/nuevo',
        ], ['@owner']);

        // La última línea de un patrón es la que manda, sin distinguir
        // mayúsculas en los dueños ni la barra inicial del patrón.
        $this->assertSame([
            '/nuevo @owner',
        ], $merged['added']);
        $this->assertSame([
            '/.larapack/hand-rolled.lock   @Owner @Other   # sangrado y con comentario',
            '.github/workflows/larapack.yml @owner',
        ], $merged['present']);
        $this->assertSame(['/CODEOWNERS @someone-else'], $merged['foreign']);

        $this->assertSame($current."\n\n".implode("\n", [
            '# LaraPack (larapack:ci): la aprobación de las excepciones #[HandRolled] y la',
            '# puerta de la CI las revisa el dueño del proyecto.',
            '/nuevo @owner',
        ])."\n", $merged['contents']);

        // Dueños en mayúsculas contra una línea en minúsculas, también.
        $this->assertSame(['/a @x'], CiGate::mergeCodeowners('/a @x', ['/a'], ['@X'])['present']);
    }

    public function test_fusionar_en_un_codeowners_vacio_o_que_acaba_en_salto(): void
    {
        $this->assertSame("# LaraPack (larapack:ci): la aprobación de las excepciones #[HandRolled] y la\n# puerta de la CI las revisa el dueño del proyecto.\n/a @o @p\n",
            CiGate::mergeCodeowners('', ['/a'], ['@o', '@p'])['contents']);

        $this->assertStringStartsWith("/x @y\n\n# LaraPack", CiGate::mergeCodeowners("/x @y\n", ['/a'], ['@o'])['contents']);

        $unchanged = CiGate::mergeCodeowners("/a @o\n", ['/a'], ['@o']);
        $this->assertSame("/a @o\n", $unchanged['contents']);
        $this->assertSame([], $unchanged['added']);
    }

    // LA ORDEN QUE PROTEGE LA RAMA

    public function test_la_orden_de_proteccion(): void
    {
        $this->assertSame(
            "gh api --method PUT 'repos/acme/app/branches/main/protection'"
            ." -F 'required_status_checks[strict]=false'"
            ." -f 'required_status_checks[contexts][]=LaraPack verify'"
            ." -F 'enforce_admins=true'"
            ." -F 'required_pull_request_reviews[require_code_owner_reviews]=true'"
            ." -F 'required_pull_request_reviews[required_approving_review_count]=1'"
            ." -F 'restrictions=null'",
            CiGate::protectionCommand('acme/app', 'main')
        );

        $this->assertStringContainsString(" -F 'enforce_admins=false'", CiGate::protectionCommand('acme/app', 'main', false));
    }

    public function test_las_comillas_de_la_shell(): void
    {
        $this->assertSame("'it'\\''s'", CiGate::shellQuote("it's"));
        $this->assertSame("''", CiGate::shellQuote(''));
    }

    public function test_que_rama_se_puede_escribir_en_la_orden(): void
    {
        foreach (['main', 'release/2.x', '_x', '.hidden'] as $branch) {
            $this->assertTrue(CiGate::isSafeBranch($branch), $branch);
        }

        foreach (['-main', 'a;b', 'a$(x)', 'a..b', 'a b', '/main'] as $branch) {
            $this->assertFalse(CiGate::isSafeBranch($branch), $branch);
        }
    }
}
