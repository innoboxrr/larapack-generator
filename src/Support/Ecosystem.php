<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use RuntimeException;
use UnexpectedValueException;

/**
 * Las versiones bendecidas del ecosistema, y la comprobación de que un
 * paquete concreto las cumple.
 *
 * El problema que resuelve es que hasta ahora cada paquete declaraba lo que
 * quería —o no declaraba nada: 23 de 29 no tenían ni `php` ni `illuminate/*`,
 * así que Composer los habría instalado en cualquier versión— y la deriva
 * solo se descubría cuando algo reventaba en producción.
 *
 * La regla vive en `ecosystem.json`, en un único sitio, y esta clase la
 * ejerce. Deliberadamente no intenta arreglar nada: informa, y el arreglo es
 * una decisión humana o un paso explícito de CI.
 */
final class Ecosystem
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    private const FILE = 'ecosystem.json';

    /** @var array<string, mixed>|null */
    private ?array $rules = null;

    public function __construct(private ?string $manifestPath = null)
    {
        $this->manifestPath ??= dirname(__DIR__, 2) . '/' . self::FILE;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        if (! is_file($this->manifestPath)) {
            throw new RuntimeException("No se encontró {$this->manifestPath}.");
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$this->manifestPath} no es JSON válido.");
        }

        return $this->rules = $decoded;
    }

    /**
     * Audita un directorio de paquete. Detecta por sí solo si es Composer,
     * npm o ambos, para no exigir un tipo que el paquete no es.
     *
     * @return array<int, array{level: string, check: string, package: string, message: string}>
     */
    public function audit(string $directory): array
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $name = basename($directory);

        $composer = $this->readJson("{$directory}/composer.json");
        $npm = $this->readJson("{$directory}/package.json");

        if ($composer === null && $npm === null) {
            return [$this->finding(self::WARNING, 'not-a-package', $name, 'No tiene composer.json ni package.json.')];
        }

        $findings = [];

        if ($composer !== null) {
            $findings = [
                ...$findings,
                ...$this->auditComposer($directory, $composer['name'] ?? $name, $composer),
            ];
        }

        if ($npm !== null) {
            $findings = [
                ...$findings,
                ...$this->auditNpm($directory, $npm['name'] ?? $name, $npm),
            ];
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<int, array<string, mixed>>
     */
    private function auditComposer(string $directory, string $name, array $composer): array
    {
        $rules = $this->rules();
        $findings = [];

        /** @var array<string, string> $require */
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        /** @var array<string, string> $dev */
        $dev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        $all = $require + $dev;

        // Campos de publicación. Sin `license` o `description` el paquete se
        // publica igual, pero queda sin identificar en Packagist.
        foreach ($rules['required']['composer'] ?? [] as $field) {
            if (! isset($composer[$field])) {
                $findings[] = $this->finding(self::WARNING, 'composer-field', $name, "Falta el campo `{$field}` en composer.json.");
            }
        }

        // La restricción de PHP. Es la más importante: sin ella Composer da
        // por bueno cualquier intérprete.
        $expectedPhp = $rules['php']['require'] ?? null;

        if ($expectedPhp !== null) {
            if (! isset($require['php'])) {
                $findings[] = $this->finding(self::ERROR, 'php-missing', $name, "No declara `php`. Debe ser `{$expectedPhp}`.");
            } else {
                $findings = [...$findings, ...$this->auditConstraint('php-version', $name, 'php', $require['php'], $expectedPhp)];
            }
        }

        // Illuminate solo se exige si el paquete realmente usa Laravel: hay
        // paquetes legítimamente agnósticos y obligarles a depender del
        // framework sería empeorarlos.
        $expectedIlluminate = $rules['laravel']['illuminate'] ?? null;
        // laravel/framework trae todos los illuminate/*: una aplicacion o un
        // paquete que lo pide a el ya esta declarando contra que Laravel funciona.
        $illuminate = array_filter($all, fn (string $k): bool => str_starts_with($k, 'illuminate/') || $k === 'laravel/framework', ARRAY_FILTER_USE_KEY);

        if ($expectedIlluminate !== null) {
            if ($illuminate === [] && $this->usesLaravel($directory)) {
                $findings[] = $this->finding(self::ERROR, 'illuminate-missing', $name, "Usa Laravel y no declara ningún `illuminate/*`. Debe ser `{$expectedIlluminate}`.");
            }

            foreach ($illuminate as $package => $constraint) {
                $findings = [...$findings, ...$this->auditConstraint('illuminate-version', $name, $package, $constraint, $expectedIlluminate)];
            }
        }

        // Testbench marca contra qué Laravel se prueba de verdad. Hoy va de
        // ^8.0 a ^11.0 en el árbol, es decir Laravel 9 a 13.
        $expectedTestbench = $rules['laravel']['testbench'] ?? null;

        if ($expectedTestbench !== null && isset($all['orchestra/testbench'])) {
            $findings = [...$findings, ...$this->auditConstraint(
                'testbench-version', $name, 'orchestra/testbench', $all['orchestra/testbench'], $expectedTestbench
            )];
        }

        // Dependencias internas: es lo que mantiene al ecosistema hablando
        // consigo mismo. Un `larapack-generator: ^5.0` impide instalar el 6.
        foreach ($rules['internal'] ?? [] as $package => $expected) {
            if (isset($all[$package])) {
                $findings = [...$findings, ...$this->auditConstraint('internal-version', $name, $package, $all[$package], $expected)];
            }
        }

        return [
            ...$findings,
            ...$this->auditTests($directory, $name),
            ...$this->auditWorkflows($directory, $name, 'composer'),
        ];
    }

    /**
     * @param  array<string, mixed>  $npm
     * @return array<int, array<string, mixed>>
     */
    private function auditNpm(string $directory, string $name, array $npm): array
    {
        $rules = $this->rules();
        $findings = [];

        foreach ($rules['required']['npm'] ?? [] as $field) {
            // sideEffects: false es un valor válido, así que hay que
            // preguntar por la clave y no por el valor.
            if (! array_key_exists($field, $npm)) {
                $findings[] = $this->finding(self::WARNING, 'npm-field', $name, "Falta el campo `{$field}` en package.json.");
            }
        }

        $expectedEngines = $rules['node']['engines'] ?? null;

        if ($expectedEngines !== null && ($npm['engines']['node'] ?? null) !== $expectedEngines) {
            $declared = $npm['engines']['node'] ?? 'nada';
            $findings[] = $this->finding(self::WARNING, 'node-engines', $name, "Declara `engines.node: {$declared}` y la línea base es `{$expectedEngines}`.");
        }

        // Las peerDependencies quedan fuera a proposito. Una libreria que
        // declara `react: ^18 || ^19` esta haciendo lo correcto: dice contra
        // que puede funcionar, no contra que se construye. Exigirle la version
        // exacta de la linea base la volveria mas estrecha sin ganar nada, y
        // un aviso que no hay que atender es como se deja de mirar el resto.
        $deps = array_merge(
            is_array($npm['dependencies'] ?? null) ? $npm['dependencies'] : [],
            is_array($npm['devDependencies'] ?? null) ? $npm['devDependencies'] : [],
        );

        foreach ($rules['js'] ?? [] as $package => $expected) {
            if (isset($deps[$package])) {
                $findings = [...$findings, ...$this->auditConstraint('js-version', $name, $package, $deps[$package], $expected)];
            }
        }

        return [
            ...$findings,
            ...$this->auditWorkflows($directory, $name, 'npm'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function auditTests(string $directory, string $name): array
    {
        $findings = [];

        if (! is_file("{$directory}/phpunit.xml") && ! is_file("{$directory}/phpunit.xml.dist")) {
            $findings[] = $this->finding(self::ERROR, 'phpunit-config', $name, 'No tiene phpunit.xml ni phpunit.xml.dist.');
        }

        $tests = glob("{$directory}/tests/**/*Test.php", GLOB_BRACE) ?: [];
        $tests = [...$tests, ...(glob("{$directory}/tests/*Test.php") ?: [])];

        if ($tests === []) {
            $findings[] = $this->finding(self::ERROR, 'no-tests', $name, 'No tiene ningún archivo *Test.php en tests/.');
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function auditWorkflows(string $directory, string $name, string $kind): array
    {
        $findings = [];
        $dir = "{$directory}/.github/workflows";

        foreach ($this->rules()['workflows'][$kind] ?? [] as $workflow) {
            if (! is_file("{$dir}/{$workflow}")) {
                $findings[] = $this->finding(self::ERROR, 'workflow-missing', $name, "Falta .github/workflows/{$workflow}.");
            }
        }

        // El bump automático etiquetaba en cada push sin correr un solo test:
        // por eso `traits` salió como 2.0.0 sin que nadie lo pidiera.
        //
        // Lo que importa no es que el archivo exista, sino de qué cuelga. Un
        // bump que espera a `workflow_run` y comprueba la conclusión ya tiene
        // puerta: es peor que el modelo de VERSION —la versión se adivina en
        // vez de declararse— pero no publica a ciegas, y llamarlo error igual
        // que al otro caso es como se deja de mirar la lista.
        if (is_file("{$dir}/bump-patch.yml")) {
            $bump = (string) file_get_contents("{$dir}/bump-patch.yml");

            $findings[] = $this->bumpIsGated($bump)
                ? $this->finding(self::WARNING, 'bump-patch', $name, 'Conserva bump-patch.yml: pasa por los tests, pero deriva la versión del último tag en vez de declararla.')
                : $this->finding(self::ERROR, 'bump-patch', $name, 'Conserva bump-patch.yml colgado de `push`: etiqueta y publica sin esperar a los tests.');
        }

        return $findings;
    }

    /**
     * Si el bump espera a la suite.
     *
     * Se miran las claves del YAML y no el texto. Buscar `workflow_run` a secas
     * daba por protegido a rvoe-manager por un comentario que explica por qué
     * no lo usa, y habría dado por desprotegido a cualquier bump que llamara a
     * la suite con `needs`, que es la forma que falla cerrada: si los tests son
     * rojos, el job del tag ni arranca.
     */
    private function bumpIsGated(string $yaml): bool
    {
        $code = (string) preg_replace('/^\s*#.*$/m', '', $yaml);

        return (bool) preg_match('/^\s*workflow_run\s*:/m', $code)
            || (bool) preg_match('/^\s*needs\s*:\s*\[?\s*[\'"]?tests\b/m', $code);
    }

    /**
     * Un paquete "usa Laravel" si su código menciona el framework. Es una
     * heurística, pero conservadora: solo sirve para no exigir illuminate a
     * quien no lo necesita, nunca para relajar una regla.
     */
    private function usesLaravel(string $directory): bool
    {
        foreach (glob("{$directory}/src/*.php") ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'Illuminate\\')) {
                return true;
            }
        }

        return is_dir("{$directory}/src/Providers")
            || is_dir("{$directory}/database/migrations")
            || is_dir("{$directory}/routes");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Compara una restriccion declarada contra la de la linea base.
     *
     * Lo que importa no es que el texto coincida, sino que la restriccion
     * *admita* la linea base. Una libreria que declara `^12.0 || ^13.0` esta
     * haciendo lo correcto: dice contra que puede funcionar, no contra que se
     * construye, y estrecharla a `^13.0` solo le quita a un consumidor de
     * Laravel 12 la posibilidad de instalarla. Es el mismo razonamiento que ya
     * deja fuera a las peerDependencies del lado npm.
     *
     * Asi que hay tres casos y no dos:
     *
     *   exacta   dice justo la linea base
     *   ancha    la admite y ademas admite cosas por debajo -> aviso, no
     *            error: es legitimo, pero la matriz de CI tiene que cubrir de
     *            verdad eso que promete
     *   estrecha no la admite -> error, ahi si hay algo roto
     *
     * @return array<int, array<string, mixed>>
     */
    private function auditConstraint(string $check, string $package, string $dependency, string $declared, string $baseline): array
    {
        $parser = new VersionParser();

        try {
            $declaredConstraint = $parser->parseConstraints($declared);
            $baselineConstraint = $parser->parseConstraints($baseline);
        } catch (UnexpectedValueException) {
            return [$this->finding(self::WARNING, $check, $package, "`{$dependency}: {$declared}` no es una restricción que se pueda leer.")];
        }

        if (! Intervals::isSubsetOf($baselineConstraint, $declaredConstraint)) {
            return [$this->finding(self::ERROR, $check, $package, "`{$dependency}: {$declared}` no admite la línea base `{$baseline}`.")];
        }

        if (! Intervals::isSubsetOf($declaredConstraint, $baselineConstraint)) {
            return [$this->finding(self::WARNING, $check, $package, "`{$dependency}: {$declared}` admite versiones fuera de la línea base `{$baseline}`; la matriz de CI tiene que cubrirlas.")];
        }

        return [];
    }

    /**
     * @return array{level: string, check: string, package: string, message: string}
     */
    private function finding(string $level, string $check, string $package, string $message): array
    {
        return compact('level', 'check', 'package', 'message');
    }
}
