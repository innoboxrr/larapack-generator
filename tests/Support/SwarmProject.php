<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Un proyecto con git en el que trabajan varias consolas a la vez: una
 * aplicación con dos paquetes locales y una rama por «agente», como las deja
 * un enjambre antes de integrarlas.
 *
 * Todo corre como en un proyecto de verdad: el `builder` del paquete en un
 * proceso aparte (con Pint, como en la aplicación) y `git` de verdad. Las
 * ramas se crean una vez (`seed`) y cada prueba trabaja en su propio clon
 * (`clone`), así que ninguna ve lo que fusionó otra.
 */
final class SwarmProject
{
    public const BRANCHES = [
        'order', 'note', 'phone', 'vip', 'phone-again', 'close-post', 'close-put', 'policy', 'policy-too',
        'hand-good', 'hand-bad', 'payment', 'refund', 'manual-migration', 'contract-only',
    ];

    public const INTERFACE_BRANCHES = ['ui-order', 'ui-note'];

    /**
     * @param  list<string>  $ui  la interfaz con la que se genera (`vue`, `react`)
     * @param  list<string>  $branches
     */
    private function __construct(public readonly string $path, private readonly array $ui = [], private readonly array $branches = self::BRANCHES) {}

    /**
     * Un paquete con interfaz Vue y React y dos ramas que añaden cada una un
     * modelo: lo que se mide son los archivos que comparten todos los modelos
     * del ámbito y no están en el manifiesto (los de idioma).
     */
    public static function seedInterface(): self
    {
        $project = new self(self::temporary(), ['vue', 'react'], self::INTERFACE_BRANCHES);

        $project->write('composer.json', self::json(['name' => 'acme/crm', 'type' => 'library', 'autoload' => ['psr-4' => ['Acme\\Crm\\' => 'src/']]]));
        $project->write('laraimport.json', self::json(['models' => [
            ['name' => 'Customer', 'props' => [['name' => 'name', 'type' => 'string', 'datatable' => true]]],
        ]]));
        $project->import('.', force: false);
        $project->initialise('Enjambre');
        $project->commit('base');

        foreach (['ui-order' => 'Order', 'ui-note' => 'Note'] as $branch => $model) {
            $project->git('checkout', '-q', '-b', $branch, 'main');
            $project->declare('.', fn (array &$d) => $d['models'][] = ['name' => $model, 'props' => [
                ['name' => 'title', 'type' => 'string', 'datatable' => true],
                ['name' => 'customer_id', 'type' => 'foreignId', 'constraint' => 'customers'],
            ]]);
            $project->commit($branch);
        }

        $project->git('checkout', '-q', 'main');

        return $project;
    }

    private function initialise(string $name): void
    {
        if (! is_dir($this->path.'/.git')) {
            $this->git('init', '-q', '-b', 'main');
        }

        $this->git('config', 'user.email', 'enjambre@example.test');
        $this->git('config', 'user.name', $name);
        $this->git('config', 'commit.gpgsign', 'false');
        // Sin esto, en Windows git reescribe los finales de línea al clonar.
        $this->git('config', 'core.autocrlf', 'false');
    }

    /**
     * El proyecto base, ya generado y comiteado en `main`, con una rama por
     * agente.
     */
    public static function seed(): self
    {
        $project = new self(self::temporary());
        $project->writeBase();

        foreach (['.', 'packages/billing', 'packages/catalog'] as $scope) {
            $project->import($scope, force: false);
        }

        $project->initialise('Enjambre');
        $project->commit('base');

        foreach (self::BRANCHES as $branch) {
            $project->git('checkout', '-q', '-b', $branch, 'main');
            $project->agent($branch);
            $project->commit($branch);
        }

        $project->git('checkout', '-q', 'main');

        return $project;
    }

    /**
     * Un clon con todas las ramas, en `main`: el puesto del integrador.
     */
    public function clone(): self
    {
        $clone = new self(self::temporary(), $this->ui, $this->branches);
        rmdir($clone->path);

        self::process(['git', 'clone', '-q', $this->path, $clone->path], dirname($clone->path));

        $clone->initialise('Integrador');

        foreach ($this->branches as $branch) {
            $clone->git('branch', '-q', $branch, 'origin/'.$branch);
        }

        return $clone;
    }

    // ─── Lo que hace cada agente ─────────────────────────────────────────────

    private function agent(string $branch): void
    {
        match ($branch) {
            // Dos agentes añaden cada uno un modelo al mismo laraimport.
            'order' => $this->declare('.', fn (array &$d) => $d['models'][] = ['name' => 'Order', 'props' => [
                ['name' => 'reference', 'type' => 'string', 'datatable' => true],
                ['name' => 'customer_id', 'type' => 'foreignId', 'constraint' => 'customers'],
            ]]),
            'note' => $this->declare('.', fn (array &$d) => $d['models'][] = ['name' => 'Note', 'props' => [
                ['name' => 'body', 'type' => 'text'],
                ['name' => 'ticket_id', 'type' => 'foreignId', 'constraint' => 'tickets'],
            ]]),

            // Tres añaden columnas al mismo modelo; dos de ellos, la misma.
            'phone' => $this->declare('.', fn (array &$d) => $d['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string', 'nullable' => true]),
            'vip' => $this->declare('.', fn (array &$d) => $d['models'][0]['props'][] = ['name' => 'vip', 'type' => 'boolean']),
            'phone-again' => $this->declare('.', fn (array &$d) => $d['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string', 'nullable' => true, 'datatable' => true]),

            // Dos declaran el mismo endpoint, cada uno con un verbo.
            'close-post' => $this->endpoint('post'),
            'close-put' => $this->endpoint('put'),

            // Dos escriben en el mismo hueco.
            'policy' => $this->replaceIn('app/Policies/CustomerPolicy.php', '/(public function viewAny\([^)]*\)[^{]*\{)/', "$1\n        // agente policy: sólo el equipo de ventas\n"),
            'policy-too' => $this->replaceIn('app/Policies/CustomerPolicy.php', '/(public function viewAny\([^)]*\)[^{]*\{)/', "$1\n        // agente policy-too: sólo administración\n"),

            // Controladores a mano: uno delega en su request, otro lleva la lógica.
            'hand-good' => $this->handWritten('Report', "        return \$request->handle();\n"),
            'hand-bad' => $this->handWritten('Shortcut', "        return \\App\\Models\\Customer::query()->where('vip', true)->get();\n"),

            // Dos añaden cada uno un modelo al mismo paquete.
            'payment' => $this->declare('packages/billing', fn (array &$d) => $d['models'][] = ['name' => 'Payment', 'props' => [
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'invoice_id', 'type' => 'foreignId', 'constraint' => 'invoices'],
            ]]),
            'refund' => $this->declare('packages/billing', fn (array &$d) => $d['models'][] = ['name' => 'Refund', 'props' => [
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'invoice_id', 'type' => 'foreignId', 'constraint' => 'invoices'],
            ]]),

            // Uno escribe a mano la migración de una columna que otro declara.
            'manual-migration' => $this->write('database/migrations/2030_01_01_000000_add_phone_to_customers_table.php', <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::table('customers', function (Blueprint $table) {
                            $table->string('phone')->nullable();
                        });
                    }
                };

                PHP),

            // Uno cambia el contrato y no vuelve a importar.
            'contract-only' => $this->declare('.', fn (array &$d) => $d['models'][0]['props'][1]['type'] = 'text', import: false),

            default => throw new RuntimeException("No hay agente {$branch}."),
        };
    }

    /**
     * Edita el laraimport de un ámbito y, como dice la guía, vuelve a importar.
     *
     * @param  \Closure(array<string, mixed>): mixed  $edit
     */
    private function declare(string $scope, \Closure $edit, bool $import = true): void
    {
        $file = $this->path.'/'.$scope.'/laraimport.json';
        $document = json_decode((string) file_get_contents($file), true);

        $edit($document);

        file_put_contents($file, self::json($document));

        if ($import) {
            $this->import($scope);
        }
    }

    /**
     * El endpoint `close` de Ticket, con su operación escrita en el hueco:
     * la rama queda en verde.
     */
    private function endpoint(string $method): void
    {
        $this->declare('.', fn (array &$d) => $d['models'][1]['endpoints'][] = ['name' => 'close', 'method' => $method]);

        $this->replaceIn('app/Models/Traits/Operations/TicketOperations.php', '/\}\s*$/', <<<PHP

                public function closeModel(\$request)
                {
                    // agente close-{$method}
                    return \$this;
                }
            }

            PHP);
    }

    private function handWritten(string $name, string $body): void
    {
        $this->write("app/Http/Requests/{$name}/SummaryRequest.php", <<<PHP
            <?php

            namespace App\\Http\\Requests\\{$name};

            use Illuminate\\Foundation\\Http\\FormRequest;

            class SummaryRequest extends FormRequest
            {
                public function authorize(): bool
                {
                    return true;
                }

                public function rules(): array
                {
                    return [];
                }

                public function handle(): array
                {
                    return ['ok' => true];
                }
            }

            PHP);

        $this->write("app/Http/Controllers/{$name}Controller.php", <<<PHP
            <?php

            namespace App\\Http\\Controllers;

            use App\\Http\\Requests\\{$name}\\SummaryRequest;

            class {$name}Controller extends Controller
            {
                public function summary(SummaryRequest \$request)
                {
            {$body}    }
            }

            PHP);
    }

    // ─── Git y el generador ──────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: string} código de salida y salida
     */
    public function builder(string ...$arguments): array
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/builder', ...$arguments],
            $this->path,
            ['LARAPACK_PINT' => dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint'],
        );
        $process->setTimeout(300);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    public function import(string $scope = '.', bool $force = true): void
    {
        $root = $this->path.($scope === '.' ? '' : '/'.$scope);
        [$code, $output] = $this->builder('larapack:import', $root.'/laraimport.json', '--root='.$root, ...($force ? ['--force'] : []), ...array_map(fn (string $ui): string => '--'.$ui, $this->ui));

        if ($code !== 0) {
            throw new RuntimeException("larapack:import en {$scope} terminó con {$code}:\n{$output}");
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function verify(): array
    {
        [$code, $output] = $this->builder('larapack:verify', '--strict', '--format=json', '--root='.$this->path);

        return [$code, (array) json_decode($output, true)];
    }

    /**
     * Los checks que dan error o aviso en `verify --strict`, sin repetir.
     *
     * @return list<string>
     */
    public function failingChecks(): array
    {
        $checks = [];

        foreach ($this->verify()[1]['findings'] ?? [] as $finding) {
            if (in_array($finding['level'], ['error', 'warning'], true)) {
                $checks[$finding['check']] = true;
            }
        }

        $checks = array_keys($checks);
        sort($checks);

        return $checks;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function reconcile(string ...$options): array
    {
        [$code, $output] = $this->builder('larapack:reconcile', '--format=json', '--root='.$this->path, ...$options);

        return [$code, (array) json_decode($output, true)];
    }

    /**
     * `git merge --no-ff --no-commit`: deja la fusión abierta, con conflictos
     * o sin ellos, que es donde trabaja el integrador.
     *
     * @return list<string> los archivos en conflicto
     */
    public function merge(string $branch): array
    {
        $this->git(false, 'merge', '-q', '--no-ff', '--no-commit', $branch);

        return array_values(array_filter(explode("\n", trim($this->git('diff', '--name-only', '--diff-filter=U')))));
    }

    public function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);
    }

    /**
     * @param  string|false  ...$arguments  con `false` delante, no falla si git sale con error
     */
    public function git(string|bool ...$arguments): string
    {
        $tolerant = ($arguments[0] ?? null) === false;
        $arguments = array_values(array_filter($arguments, 'is_string'));

        return self::process(['git', ...$arguments], $this->path, $tolerant);
    }

    /**
     * @param  list<string>  $command
     */
    private static function process(array $command, string $cwd, bool $tolerant = false): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(300);
        $process->run();

        if (! $tolerant && ! $process->isSuccessful()) {
            throw new RuntimeException(implode(' ', $command)." falló:\n".$process->getOutput().$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    // ─── Archivos ────────────────────────────────────────────────────────────

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path.'/'.$relative);
    }

    public function has(string $relative): bool
    {
        return is_file($this->path.'/'.$relative);
    }

    public function write(string $relative, string $content): void
    {
        $file = $this->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $content);
    }

    public function replaceIn(string $relative, string $pattern, string $replacement): void
    {
        $replaced = preg_replace($pattern, $replacement, $this->read($relative), 1, $count);

        if ($count !== 1 || ! is_string($replaced)) {
            throw new RuntimeException("No se encontró {$pattern} en {$relative}.");
        }

        $this->write($relative, $replaced);
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(string $scope = '.'): array
    {
        return (array) json_decode($this->read(($scope === '.' ? '' : $scope.'/').'laraimport.json'), true);
    }

    /**
     * Los archivos versionados, relativos a la raíz.
     *
     * @return list<string>
     */
    public function tracked(): array
    {
        return array_values(array_filter(explode("\n", trim($this->git('ls-files')))));
    }

    /**
     * Lo que generaría de cero el contrato que hay hoy en cada ámbito, en un
     * directorio aparte: la referencia contra la que se mide la deriva.
     */
    public function regenerated(): self
    {
        $fresh = new self(self::temporary(), $this->ui, $this->branches);

        foreach (['.', 'packages/billing', 'packages/catalog'] as $scope) {
            $prefix = $scope === '.' ? '' : $scope.'/';

            if (! $this->has($prefix.'laraimport.json')) {
                continue;
            }

            $fresh->write($prefix.'composer.json', $this->read($prefix.'composer.json'));
            $fresh->write($prefix.'laraimport.json', $this->read($prefix.'laraimport.json'));
            $fresh->import($scope, force: false);
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function json(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function writeBase(): void
    {
        $this->write('composer.json', self::json([
            'name' => 'acme/app',
            'type' => 'project',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'repositories' => [['type' => 'path', 'url' => 'packages/*']],
        ]));

        $this->write('app/Http/Controllers/Controller.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class Controller\n{\n    //\n}\n");

        $this->write('laraimport.json', self::json(['models' => [
            ['name' => 'Customer', 'props' => [
                ['name' => 'name', 'type' => 'string', 'datatable' => true],
                ['name' => 'email', 'type' => 'string'],
            ]],
            ['name' => 'Ticket', 'props' => [
                ['name' => 'subject', 'type' => 'string', 'datatable' => true],
                ['name' => 'customer_id', 'type' => 'foreignId', 'constraint' => 'customers'],
            ]],
        ]]));

        foreach (['billing' => ['Acme\\Billing\\', 'Invoice', 'number'], 'catalog' => ['Acme\\Catalog\\', 'Product', 'title']] as $package => [$namespace, $model, $column]) {
            $this->write("packages/{$package}/composer.json", self::json([
                'name' => "acme/{$package}",
                'type' => 'library',
                'autoload' => ['psr-4' => [$namespace => 'src/']],
            ]));

            $this->write("packages/{$package}/laraimport.json", self::json(['models' => [
                ['name' => $model, 'props' => [
                    ['name' => $column, 'type' => 'string', 'datatable' => true],
                    ['name' => 'total', 'type' => 'decimal'],
                ]],
            ]]));
        }
    }

    public function cleanup(): void
    {
        self::remove($this->path);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            // Los objetos de git son de sólo lectura: en Windows no se borran así.
            @chmod($path, 0666);
            @unlink($path);

            return;
        }

        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    private static function temporary(): string
    {
        $path = sys_get_temp_dir().'/larapack-swarm-'.bin2hex(random_bytes(6));

        if (! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException("No se pudo crear {$path}.");
        }

        return (string) realpath($path);
    }
}
