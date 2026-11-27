<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use RuntimeException;

/**
 * Un paquete desechable en el que los hooks se ejecutan de verdad: con
 * `vendor/bin/builder` (que delega en el builder de este repositorio), un
 * controlador y su request, y como repositorio git si se pide.
 *
 * Es lo que hace falta para probar lo que un PHPUnit en proceso no ve: el
 * script del hook de git que lanza git, y el comando de cada agente que lanza
 * la shell, con su entrada y sus variables.
 */
final class HookProject
{
    public readonly string $path;

    public function __construct(bool $git = true)
    {
        $path = sys_get_temp_dir().'/larapack-hooks-'.bin2hex(random_bytes(6));
        mkdir($path.'/src/Http/Requests/Note', 0777, true);
        mkdir($path.'/src/Http/Controllers', 0777, true);
        mkdir($path.'/vendor/bin', 0777, true);
        $this->path = str_replace('\\', '/', (string) realpath($path));

        file_put_contents($this->path.'/composer.json', '{ "name": "acme/notes", "autoload": { "psr-4": { "Acme\\\\Notes\\\\": "src/" } } }'."\n");
        file_put_contents($this->path.'/.gitignore', "vendor/\n");
        file_put_contents($this->path.'/vendor/bin/builder', '<?php'."\nrequire ".var_export(dirname(__DIR__, 2).'/builder', true).";\n");
        file_put_contents($this->path.'/src/Http/Requests/Note/IndexRequest.php', <<<'PHP'
        <?php

        namespace Acme\Notes\Http\Requests\Note;

        use Illuminate\Foundation\Http\FormRequest;

        class IndexRequest extends FormRequest
        {
            public function handle(): array
            {
                return [];
            }
        }
        PHP);
        $this->controller(true);

        if ($git) {
            $this->git('init', '--quiet');
            $this->git('config', 'user.name', 'larapack');
            $this->git('config', 'user.email', 'larapack@example.test');
            $this->git('config', 'commit.gpgsign', 'false');
        }
    }

    /**
     * El controlador: limpio (`return $request->handle();`) o con la
     * validación dentro, que es la deriva que todo esto frena.
     */
    public function controller(bool $clean): string
    {
        $body = $clean
            ? "use Acme\\Notes\\Http\\Requests\\Note\\IndexRequest;\n\nclass NoteController\n{\n    public function index(IndexRequest \$request)\n    {\n        return \$request->handle();\n    }\n}\n"
            : "use Illuminate\\Http\\Request;\n\nclass NoteController\n{\n    public function index(Request \$request)\n    {\n        \$data = \$request->validate(['q' => ['nullable']]);\n\n        return response()->json(\$data);\n    }\n}\n";
        $file = $this->path.'/src/Http/Controllers/NoteController.php';

        file_put_contents($file, "<?php\n\nnamespace Acme\\Notes\\Http\\Controllers;\n\n".$body);

        return $file;
    }

    public function write(string $relative, string $contents): void
    {
        $file = $this->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }

    /**
     * Una orden de git en el proyecto; la salida, o una excepción si falla.
     */
    public function git(string ...$arguments): string
    {
        $result = $this->run(['git', ...$arguments]);

        if ($result['code'] !== 0) {
            throw new RuntimeException('git '.implode(' ', $arguments).' falló: '.$result['stderr']);
        }

        return $result['stdout'];
    }

    /**
     * Un proceso en el proyecto, sin terminal: con `setsid` donde existe,
     * para que el hook no pueda abrir /dev/tty aunque PHPUnit corra en una.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function run(array $command, string $stdin = '', array $env = [], bool $detached = false): array
    {
        if ($detached && self::canDetach()) {
            $command = ['setsid', '-w', ...$command];
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->path,
            $env === [] ? null : array_merge(getenv(), $env)
        );

        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo ejecutar '.implode(' ', $command));
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Si un proceso se puede lanzar sin terminal de control.
     */
    public static function canDetach(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/setsid');
    }

    /**
     * Si PHPUnit corre sin terminal de control: entonces ningún hijo puede
     * abrir /dev/tty, con o sin `setsid`.
     */
    public static function withoutTerminal(): bool
    {
        $tty = @fopen('/dev/tty', 'r');

        if ($tty === false) {
            return true;
        }

        fclose($tty);

        return false;
    }

    public function cleanup(): void
    {
        self::remove($this->path);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
