<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lo que la guardia lee de lo que le llega —el payload del hook, una orden
 * de Bash, una ruta— y lo que contesta, pieza a pieza.
 *
 * GuardTest y GuardHandRolledTest prueban los hooks enteros. Aquí se fija
 * cada lectura con los casos que un cambio pequeño movería sin ruido: una
 * orden anidada tres veces, un heredoc con CRLF, una ruta de Windows con `..`,
 * un directorio de estado con un permiso de más. La guardia es lo que impide
 * que un agente se apruebe a sí mismo una excepción: lo que lee mal, lo deja
 * pasar.
 *
 * Con los avisos convertidos en excepciones, como bajo Artisan.
 */
final class GuardInputsTest extends TestCase
{
    private int $errorReporting;

    private const LOCK = '.larapack/hand-rolled.lock';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-guard-in-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->root = (string) realpath($this->root);
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
        Guard::useTemporaryDirectory(null);
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            @chmod($path, 0700);

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

    /**
     * @return array<string, mixed>
     */
    private function bash(string $command): array
    {
        return ['cwd' => $this->root, 'tool_input' => ['command' => $command]];
    }

    /**
     * Los candados que escribe la orden, relativos a la raíz.
     *
     * @return list<string>
     */
    private function writes(string $command): array
    {
        return array_map(
            fn (string $lock): string => Guard::relative($this->root, $lock) ?? $lock,
            Guard::shellWritesLocks($this->bash($command))
        );
    }

    private function approves(string $command): bool
    {
        return Guard::approvesHandRolled($this->bash($command));
    }

    private function nested(string $command, int $times): string
    {
        for ($i = 0; $i < $times; $i++) {
            $command = 'bash -c '.escapeshellarg($command);
        }

        return $command;
    }

    // LO QUE TOCÓ LA HERRAMIENTA

    public function test_los_archivos_tocados(): void
    {
        $payload = ['cwd' => $this->root, 'tool_input' => ['edits' => [
            ['file_path' => 'a.php'],
            ['file_path' => '  '],
            ['file_path' => $this->root.'/a.php'],
            'nada',
            ['file_path' => 'b.php'],
        ]]];

        $this->assertSame([$this->root.'/a.php', $this->root.'/b.php'], Guard::touchedFiles($payload));
        $this->assertSame([], Guard::touchedFiles(['tool_input' => 'nada']));
    }

    public function test_los_candados_tocados(): void
    {
        $repeated = ['cwd' => $this->root, 'tool_input' => [
            'file_path' => 'x/'.self::LOCK,
            'command' => 'tee x/'.self::LOCK.' x/'.self::LOCK.' '.self::LOCK,
        ]];
        $this->assertSame([$this->root.'/x/'.self::LOCK, $this->root.'/'.self::LOCK], Guard::touchedLocks($repeated));
        $this->assertSame([$this->root.'/x/'.self::LOCK, $this->root.'/'.self::LOCK], Guard::shellWritesLocks($repeated));

        $payload = ['cwd' => $this->root, 'tool_input' => [
            'file_path' => self::LOCK,
            'edits' => [['file_path' => self::LOCK.'.bak'], ['file_path' => 'x/.larapack/Hand-Rolled.lock. ']],
            'command' => 'tee '.self::LOCK,
        ]];

        $this->assertSame([$this->root.'/'.self::LOCK, $this->root.'/x/.larapack/Hand-Rolled.lock. '], Guard::touchedLocks($payload));
    }

    // LO QUE ESCRIBE UNA ORDEN

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function escrituras(): array
    {
        $lock = self::LOCK;

        return [
            'redirección' => ["echo x > {$lock}", [$lock]],
            'sólo el destino de la redirección' => ["echo x > out.txt {$lock}", []],
            'tee con varios' => ["tee a/{$lock} b/{$lock}", ["a/{$lock}", "b/{$lock}"]],
            'un programa de Windows' => ["'C:\\Tools\\TEE.EXE' {$lock}", [$lock]],
            'detrás de variables y envoltorios' => ["X=1 sudo env tee {$lock}", [$lock]],
            'una variable con la ruta no es un destino' => ["L={$lock} tee out.txt", []],
            'un programa con = en el nombre no es una variable' => ["./run-x=1 tee {$lock}", []],
            'dd' => ["dd if=/dev/null of={$lock} bs=1", [$lock]],
            'sed -i' => ["sed -i 's/a/b/' {$lock}", [$lock]],
            'sed -i con dos' => ["sed -i 's/a/b/' a/{$lock} b/{$lock}", ["a/{$lock}", "b/{$lock}"]],
            'una ruta con espacio entre comillas escapadas' => ['bash -c "tee \"x y/.larapack/hand-rolled.lock\""', ['x y/.larapack/hand-rolled.lock']],
            'sed sin -i sólo lee' => ["sed 's/a-i/b/' {$lock}", []],
            'php -r que lo nombra' => ["php8.4 -r \"file_put_contents('.larapack/HAND-ROLLED.LOCK', '');\"", [$lock]],
            'un programa que sólo empieza como node' => ['nodemon -e js,hand-rolled.lock', []],
            'un programa que sólo acaba como ruby' => ["myruby -e 'hand-rolled.lock'", []],
            'escapado fuera de comillas' => ['tee .larapack/hand\\-rolled.lock', [$lock]],
            'el escape de las comillas dobles' => ['bash -c "tee \\".larapack/hand-rolled.lock\\""', [$lock]],
            'bash -ce' => ["bash -ce 'tee {$lock}'", [$lock]],
            'bash -Ec' => ["bash -Ec 'tee {$lock}'", [$lock]],
            'el here-string de una shell' => ["sh <<< \"tee {$lock}\"", [$lock]],
            'un texto que acaba en -c no es una orden' => ["echo pre-c 'tee {$lock}'", []],
            'una barra al final no avisa' => ['tee x\\', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('escrituras')]
    public function test_que_candados_escribe_una_orden(string $command, array $expected): void
    {
        $this->assertSame($expected, $this->writes($command));
    }

    public function test_hasta_tres_niveles_de_shell_dentro_de_otra(): void
    {
        $tee = 'tee '.self::LOCK;

        // Hasta tres se leen: lo que escribe y lo que aprueba, y lo que no
        // aprueba no se bloquea.
        $this->assertSame([self::LOCK], $this->writes($this->nested($tee, 3)));
        $this->assertTrue($this->approves($this->nested('larapack:hand-rolled approve', 3)));
        $this->assertFalse($this->approves($this->nested('larapack:hand-rolled list', 3)));
        $this->assertFalse($this->approves($this->nested($tee, 3)));

        $heredoc = fn (string $body): string => "bash <<EOF\n{$body}\nEOF";
        $this->assertTrue($this->approves($heredoc($heredoc($heredoc('larapack:hand-rolled approve')))));
        $this->assertFalse($this->approves($heredoc($heredoc($heredoc('larapack:hand-rolled list')))));
    }

    /**
     * Más adentro no se lee, y antes se daba por bueno: cuatro `bash -c`
     * alrededor de la aprobación pasaban. Ahora falla cerrado: lo que no se
     * termina de leer cuenta como aprobación, sea lo que sea, y la guardia lo
     * bloquea. Una shell vacía no ejecuta nada.
     */
    public function test_mas_de_tres_niveles_de_shell_falla_cerrado(): void
    {
        $heredoc = fn (string $body): string => "bash <<EOF\n{$body}\nEOF";

        $this->assertTrue($this->approves($this->nested('larapack:hand-rolled approve', 4)));
        $this->assertTrue($this->approves($this->nested('tee '.self::LOCK, 4)));
        $this->assertTrue($this->approves($this->nested('larapack:hand-rolled list', 4)));
        $this->assertTrue($this->approves($this->nested('larapack:hand-rolled approve', 7)));
        $this->assertTrue($this->approves($heredoc($heredoc($heredoc($heredoc('larapack:hand-rolled approve'))))));
        $this->assertTrue($this->approves($this->nested('eval larapack:hand-rolled approve', 3)));

        // Lo que no lee no lo inventa: ningún candado con nombre.
        $this->assertSame([], $this->writes($this->nested('tee '.self::LOCK, 4)));

        $this->assertFalse($this->approves($this->nested("bash -c ''", 3)));
        $this->assertFalse($this->approves($this->nested("bash -c '  '", 3)));
        $this->assertFalse($this->approves($heredoc($heredoc($heredoc("bash <<EOF\n\nEOF")))));
    }

    // LA APROBACIÓN

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function aprobaciones(): array
    {
        return [
            'tal cual' => ['php artisan larapack:hand-rolled approve', true],
            'en mayúsculas' => ['php artisan LARAPACK:HAND-ROLLED APPROVE', false],
            'el nombre en mayúsculas' => ['php artisan LaraPack:Hand approve', true],
            'algo pegado detrás no es el comando' => ['echo larapack:hand-rolled2 approve', false],
            'algo pegado delante tampoco' => ['echo see:larapack:hand-rolled approve', false],
            'continuada con CRLF' => ["larapack:hand-rolled appr\\\r\nove", true],
            'continuada con LF' => ["larapack:hand-rolled appr\\\nove", true],
            'una barra entre comillas dobles se queda' => ['larapack:hand-rolled "appro\\ve"', false],
            'un here-string no abre un heredoc' => ["cat <<< x\nlarapack:hand-rolled approve", true],
            'el here-string de una shell son órdenes' => ["bash <<< 'larapack:hand-rolled approve'", true],
            'el here-string de una shell, y algo detrás' => ["bash <<< 'larapack:hand-rolled approve'; echo ok", true],
            'sólo la cadena del here-string' => ["bash <<< 'echo' 'larapack:hand-rolled approve'", false],
            'un here-string pegado' => ["cat <<<'larapack:hand-rolled approve'", false],
            'el here-string de una shell de Windows' => ["'C:\\Git\\bin\\bash' <<< 'larapack:hand-rolled approve'", true],
            'los argumentos de un guion no son órdenes' => ["bash script.sh 'larapack:hand-rolled approve'", false],
            'una redirección de entrada no es un heredoc' => ["cat < archivo\nlarapack:hand-rolled approve", true],
            'un escape al final de la palabra' => ['larapack:hand-rolled approv\\e', true],
            'el here-string de cat es texto' => ["cat <<< 'larapack:hand-rolled approve'", false],
            'tras el heredoc vuelven las órdenes' => ["cat <<EOF\ntexto\nEOF\nlarapack:hand-rolled approve", true],
            'dentro del heredoc de cat no' => ["cat <<EOF\nlarapack:hand-rolled approve\nEOF", false],
            'un heredoc con comillas y espacio' => ["cat << 'EOF';\nlarapack:hand-rolled approve\nEOF\n", false],
            'un heredoc sin cerrar' => ["cat <<EOF\nlarapack:hand-rolled approve", false],
            'un heredoc con CRLF' => ["cat <<EOF\r\ntexto\r\nEOF\r\nlarapack:hand-rolled approve", true],
            'un heredoc con tabuladores' => ["cat <<-EOF\n\ttexto\n\tEOF\nlarapack:hand-rolled approve", true],
            'sin <<- los tabuladores cuentan' => ["cat <<EOF\n\tEOF\nlarapack:hand-rolled approve\nEOF", false],
            'el heredoc de una shell de Windows' => ["'C:\\Git\\bin\\bash' <<EOF\nlarapack:hand-rolled approve\nEOF", true],
            'dos heredocs en una línea' => ["cat <<A <<B\nuno\nA\ndos\nB\nlarapack:hand-rolled approve", true],
            // Sin delimitador no hay heredoc, y leer el que no hay no avisa.
            'un << al final' => ['larapack:hand-rolled approve; cat <<', true],
            'un << y un espacio al final' => ['larapack:hand-rolled approve; cat << ', true],
        ];
    }

    #[DataProvider('aprobaciones')]
    public function test_si_aprueba_una_excepcion(string $command, bool $approves): void
    {
        $this->assertSame($approves, $this->approves($command));
    }

    public function test_el_mensaje_del_candado(): void
    {
        $this->assertSame(
            "LaraPack: /p/.larapack/hand-rolled.lock es la aprobación del dueño del proyecto, no un archivo que se edite.\n"
            ."LaraPack: `larapack:hand-rolled approve` y `revoke` los corre el dueño del proyecto, no un agente.\n"
            .Guard::LOCK_RULE."\n"
            .'Ya está hecho: deshazlo (git checkout -- .larapack/hand-rolled.lock, o como estaba) antes de seguir.',
            Guard::lockMessage(['/p/.larapack/hand-rolled.lock'], true, true)
        );
    }

    // RUTAS

    public function test_el_directorio_de_trabajo(): void
    {
        mkdir($this->root.'/real');
        symlink($this->root.'/real', $this->root.'/enlace');

        // El del hook, resuelto: un enlace no es otro proyecto.
        $this->assertSame($this->root.'/real', Guard::workingDirectory(['cwd' => $this->root.'/enlace']));
        // Sin él, o si no existe, el del proceso.
        $this->assertSame(str_replace('\\', '/', (string) getcwd()), Guard::workingDirectory(['cwd' => $this->root.'/no-existe']));
        $this->assertSame(str_replace('\\', '/', (string) getcwd()), Guard::workingDirectory([]));
    }

    public function test_el_proyecto_de_un_archivo(): void
    {
        mkdir($this->root.'/pkg/.larapack/x', 0777, true);
        file_put_contents($this->root.'/pkg/.larapack/manifest.json', '{}');
        mkdir($this->root.'/pkg/sub');

        $this->assertSame($this->root.'/pkg', Guard::projectOf($this->root.'/pkg/sub/../sub/File.php'));
        $this->assertNull(Guard::projectOf($this->root.'/File.php'));
    }

    public function test_la_ruta_relativa_a_la_raiz(): void
    {
        $this->assertSame('x/A.php', Guard::relative('/a/b/', '/a/b/x/A.php'));
        $this->assertSame('x/A.php', Guard::relative('C:\\a\\b', 'C:\\a\\b\\x\\A.php'));
        $this->assertNull(Guard::relative('/a/b', '/a/bc/x.php'));
        $this->assertNull(Guard::relative('/a/b', '/a/b'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rutas(): array
    {
        return [
            'con ..' => ['/a/b/../c.php', '/a/c.php'],
            'que sube de la raíz' => ['/a/../../c.php', '/c.php'],
            'de Windows' => ['C:\\a\\b\\..\\c.php', 'C:/a/c.php'],
            'de Windows con barras de más' => ['c:///a//./b.php', 'c:/a/b.php'],
            'UNC' => ['//server/share/../x.php', '//server/x.php'],
            'relativa con algo que parece una unidad' => ['sub/C:/x.php', '/BASE/sub/C:/x.php'],
        ];
    }

    #[DataProvider('rutas')]
    public function test_las_rutas_se_resuelven_sin_mirar_el_disco(string $path, string $expected): void
    {
        $files = Guard::touchedFiles(['cwd' => $this->root, 'tool_input' => ['file_path' => $path]]);

        $this->assertSame([str_replace('/BASE', $this->root, $expected)], $files);
    }

    public function test_desde_la_raiz_del_disco(): void
    {
        $this->assertSame(['/no-existe/x.php'], Guard::touchedFiles(['cwd' => '/', 'tool_input' => ['file_path' => 'no-existe/x.php']]));
    }

    // QUÉ VIGILA

    private function manifest(): Manifest
    {
        mkdir($this->root.'/.larapack', 0777, true);
        file_put_contents($this->root.'/.larapack/manifest.json', json_encode([
            'version' => 1,
            'package' => ['namespace' => '', 'files' => ['src/Providers/X.php' => ['stub' => 'Package/Provider.txt', 'hash' => 'h']]],
            'models' => ['Post' => ['namespace' => '', 'files' => [
                'src/Models/Post.php' => ['stub' => 'Model/ModelTemplate.txt', 'hash' => 'h'],
                'src/Models/Traits/Operations/PostOperations.php' => ['stub' => 'ModelTraits/Operations.txt', 'hash' => 'h'],
                'src/Http/Controllers/PostController.php' => ['stub' => 'Controller/ControllerTemplate.txt', 'hash' => 'h'],
            ]]],
        ]));

        return new Manifest($this->root);
    }

    public function test_que_archivos_son_arquitectura(): void
    {
        $manifest = $this->manifest();

        $this->assertTrue(Guard::watches('src/Http/Controllers/Mine.php', $manifest));
        $this->assertTrue(Guard::watches('src/Models/Post.php', $manifest));
        $this->assertTrue(Guard::watches('src/Providers/X.php', $manifest));
        $this->assertFalse(Guard::watches('src/Models/Traits/Operations/PostOperations.php', $manifest));
        $this->assertFalse(Guard::watches('src/Support/Mine.php', $manifest));
        $this->assertFalse(Guard::watches('vendor/acme/x/src/Http/Controllers/A.php', $manifest));

        $this->assertTrue(Guard::isHandWrittenController('src/Http/Controllers/Mine.php', $manifest));
        $this->assertFalse(Guard::isHandWrittenController('src/Http/Controllers/PostController.php', $manifest));
        $this->assertFalse(Guard::isHandWrittenController('src/Http/Controllers/Controller.php', $manifest));
        $this->assertFalse(Guard::isHandWrittenController('src/Http/Controllers/Mine.phpx', $manifest));
        $this->assertFalse(Guard::isHandWrittenController('src/Models/Mine.php', $manifest));
    }

    public function test_los_errores_de_un_archivo(): void
    {
        $findings = [
            ['level' => 'warning', 'file' => 'a.php'],
            ['level' => 'error', 'file' => 'b.php'],
            ['level' => 'error', 'file' => './a.php'],
            ['level' => 'error'],
        ];

        $this->assertSame([['level' => 'error', 'file' => './a.php']], Guard::errorsFor($findings, 'a.php'));
        $this->assertCount(3, Guard::errorsFor($findings));
    }

    // LO QUE SE DICE

    public function test_el_mensaje_al_agente(): void
    {
        $errors = [];

        for ($i = 1; $i <= 9; $i++) {
            $errors[] = ['check' => 'c', 'file' => "f{$i}.php", 'message' => "m{$i}"];
        }

        $errors[0] = ['check' => "con\nsalto", 'file' => 5, 'message' => 'sin archivo'];
        // Lo que no es texto —un JSON de verify que no es el de verify— no se
        // convierte: «Array» no le dice nada a nadie, y avisaría.
        $errors[2] = ['check' => ['c'], 'file' => 'f3.php', 'message' => ['m3']];

        $lines = explode("\n", Guard::message('Algo falla.', $errors, $this->root));

        $this->assertSame([
            'Algo falla.',
            '  - [con\u{000A}salto] sin archivo',
            '  - [c] f2.php: m2',
            '  - [error] f3.php: ',
        ], array_slice($lines, 0, 4));
        $this->assertSame('  - [c] f8.php: m8', $lines[8]);
        $this->assertSame('  … y 1 más.', $lines[9]);
        $this->assertSame(Guard::RULE, $lines[10]);
        $this->assertSame('Corrígelo y comprueba con: php vendor/bin/builder larapack:verify --format=json', $lines[11]);

        // Con ocho justos no hay «y más»; en una aplicación, Artisan.
        touch($this->root.'/artisan');
        $lines = explode("\n", Guard::message('Algo falla.', array_slice($errors, 0, 8), $this->root));
        $this->assertCount(11, $lines);
        $this->assertSame('Corrígelo y comprueba con: php artisan larapack:verify --format=json', $lines[10]);
        $this->assertSame('php artisan', Guard::commandPrefix($this->root));
    }

    public function test_los_recordatorios(): void
    {
        $this->assertSame(
            'LaraPack: a\u{000A}b.php es un controlador escrito a mano y DEBE seguir el mismo patrón que los generados: '
            .'cada acción es `return $request->handle();` y nada más. Si alguna acción valida, autoriza, consulta o arma '
            .'la respuesta, muévelo a un FormRequest con authorize(), rules() y handle(), que delega en la operación del '
            .'modelo o en una clase de soporte. Un trait de autorización compartido va en Http/Requests/Concerns, no en '
            .'Http/Controllers.',
            Guard::controllerReminder("a\nb.php")
        );

        $this->assertSame(
            'LaraPack: T.php es un trait dentro de Http/Controllers, y ése no es su sitio: lo que comparten varias '
            .'acciones —autorización, validación, consultas— va en una request base o en un trait de Http/Requests/Concerns, '
            .'o en una clase de soporte, y los controladores se quedan en `return $request->handle();`.',
            Guard::traitReminder('T.php')
        );
    }

    // EL ESTADO DE LA SESIÓN

    public function test_lo_que_apunta_la_sesion(): void
    {
        Guard::useTemporaryDirectory($this->root.'/tmp/');
        mkdir($this->root.'/tmp');
        $payload = ['session_id' => 'sesión-1'];
        $directory = Guard::stateDirectory();

        $this->assertSame($this->root.'/tmp/larapack-guard-'.posix_geteuid(), $directory);

        Guard::remember($payload, $this->root.'/a.php');
        Guard::remember($payload, $this->root.'/b.php');
        Guard::remember($payload, $this->root.'/a.php');

        $this->assertSame($this->root."/a.php\n".$this->root."/b.php\n", file_get_contents($directory.'/'.sha1('sesión-1').'.txt'));
        $this->assertSame(['a.php', 'b.php'], Guard::sessionFiles($payload, $this->root));

        // Sin sesión, o en blanco, no se apunta nada.
        Guard::remember(['session_id' => '  '], $this->root.'/c.php');
        $this->assertNull(Guard::sessionFiles(['session_id' => '  '], $this->root));
        $this->assertSame(['.', '..', sha1('sesión-1').'.txt'], scandir($directory));
    }

    public function test_el_directorio_de_estado_solo_si_es_de_fiar(): void
    {
        $uid = posix_geteuid();
        $directory = $this->root.'/larapack-guard-'.$uid;

        $this->assertNull(Guard::stateDirectoryIn($this->root, null, false));
        $this->assertSame($this->root.'/larapack-guard', Guard::stateDirectoryIn($this->root.'/', null, true));
        $this->assertSame($directory, Guard::stateDirectoryIn($this->root.'//', $uid, false));
        $this->assertSame(0700, fileperms($directory) & 0777);

        chmod($directory, 0701);
        $this->assertSame($directory, Guard::stateDirectoryIn($this->root, $uid, false));

        foreach ([0702, 0720, 0777] as $mode) {
            chmod($directory, $mode);
            $this->assertNull(Guard::stateDirectoryIn($this->root, $uid, false), decoct($mode));
        }

        rmdir($directory);
        symlink($this->root, $directory);
        $this->assertNull(Guard::stateDirectoryIn($this->root, $uid, false));

        unlink($directory);
        touch($directory);
        $this->assertNull(Guard::stateDirectoryIn($this->root, $uid, false));
        $this->assertNull(Guard::stateDirectoryIn($directory, $uid, false));
        // En Windows no se mira de quién es, pero tiene que poder crearse.
        $this->assertNull(Guard::stateDirectoryIn($directory, null, true));
    }

    public function test_lo_que_git_ve_cambiado(): void
    {
        $git = fn (string ...$arguments) => exec('git -C '.escapeshellarg($this->root).' -c user.name=t -c user.email=t@t '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1');

        $this->assertNull(Guard::sessionFiles([], $this->root));

        $git('init', '-q');
        file_put_contents($this->root.'/original-name.php', '<?php');
        $git('add', '.');
        $git('commit', '-qm', 'x');
        $git('mv', 'original-name.php', 'renamed.php');
        file_put_contents($this->root.'/a', '');

        // El renombrado trae detrás su ruta de origen, que ya no es del árbol.
        $this->assertSame(['renamed.php', 'a'], Guard::sessionFiles([], $this->root));
    }

    // ---- Auditoría de seguridad de 9.4.0: entradas que no se leían ----

    /**
     * La `shell` de Codex y las de un servidor MCP mandan la orden ya partida
     * en palabras: no era una cadena, y pasaba sin leerse.
     */
    public function test_una_orden_que_llega_como_lista_de_palabras_se_lee_igual(): void
    {
        $cwd = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
        $approve = ['tool_input' => ['command' => ['bash', '-lc', 'php vendor/bin/builder larapack:hand-rolled approve --all']]];
        $direct = ['tool_input' => ['command' => ['php', 'artisan', 'larapack:hand-rolled', 'approve', "it's"]]];
        $bypass = ['tool_input' => ['cmd' => ['git', 'commit', '--no-verify', '-m', 'x']]];
        $write = ['cwd' => $cwd, 'tool_input' => ['command' => ['tee', '.larapack/hand-rolled.lock']]];
        $harmless = ['tool_input' => ['command' => ['php', 'artisan', 'larapack:verify']]];

        $this->assertTrue(Guard::approvesHandRolled($approve));
        $this->assertTrue(Guard::approvesHandRolled($direct));
        $this->assertSame(['git commit --no-verify'], Guard::bypassesGitHooks($bypass));
        $this->assertSame([$cwd.'/.larapack/hand-rolled.lock'], Guard::touchedLocks($write));
        $this->assertFalse(Guard::approvesHandRolled($harmless));
        $this->assertFalse(Guard::approvesHandRolled(['tool_input' => ['command' => ['php', 7]]]));
    }

    /**
     * Un cuaderno y las herramientas de archivos de un servidor MCP no
     * nombran su archivo `file_path`.
     */
    public function test_el_candado_se_reconoce_con_cualquier_nombre_del_campo_de_ruta(): void
    {
        $cwd = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));

        foreach (['file_path', 'notebook_path', 'path', 'filePath', 'target_file'] as $key) {
            $this->assertSame(
                [$cwd.'/.larapack/hand-rolled.lock'],
                Guard::touchedLocks(['cwd' => $cwd, 'tool_input' => [$key => '.larapack/hand-rolled.lock']]),
                $key
            );
        }

        $this->assertSame([], Guard::touchedLocks(['cwd' => $cwd, 'tool_input' => ['path' => 'src/Models/Post.php']]));
    }
}
