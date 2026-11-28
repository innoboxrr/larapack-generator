<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El usuario de una aplicación, generado como cualquier otro modelo.
 *
 * El modelo que genera LaraPack extiende Model: un User así no podía iniciar
 * sesión, y las aplicaciones escribían el suyo a mano y le añadían los traits
 * generados. `authenticatable: true` lo genera listo para entrar.
 */
final class AuthenticatableModelTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $user
     */
    private function import(array $user = []): void
    {
        $this->useProject(FakeProject::application());

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [
            [
                'name' => 'User',
                'authenticatable' => true,
                'props' => [
                    ['name' => 'name', 'type' => 'string', 'datatable' => true],
                    ['name' => 'email', 'type' => 'string', 'datatable' => true],
                    ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true],
                    ['name' => 'password', 'type' => 'string', 'datatable' => true],
                ],
                ...$user,
            ],
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    public function test_el_usuario_extiende_authenticatable_con_notificaciones_y_tokens(): void
    {
        $this->import();

        $model = $this->project->read('app/Models/User.php');

        $this->assertStringContainsString('use Illuminate\\Foundation\\Auth\\User as Authenticatable;', $model);
        $this->assertStringContainsString('class User extends Authenticatable', $model);
        $this->assertStringContainsString('HasApiTokens,', $model);
        $this->assertStringContainsString('Notifiable,', $model);
        $this->assertStringNotContainsString('use Illuminate\\Database\\Eloquent\\Model;', $model);
        $this->assertStringNotContainsString('@larapack:', $model);
    }

    public function test_oculta_la_contrasena_y_la_cifra_al_asignarla(): void
    {
        $this->import();

        $model = $this->project->read('app/Models/User.php');

        $this->assertMatchesRegularExpression("/protected \\\$hidden = \[\s*'password', 'remember_token'\s*\];/", $model);
        $this->assertStringContainsString("'password' => 'hashed'", $model);
        $this->assertStringContainsString("'email_verified_at' => 'datetime'", $model);
    }

    /**
     * La contraseña se declaró con `datatable: true` a propósito.
     */
    public function test_la_contrasena_no_sale_en_la_tabla_ni_en_la_exportacion(): void
    {
        $this->import();

        $this->assertDoesNotMatchRegularExpression("/\\\$export_cols = \[[^\]]*'password'/", $this->project->read('app/Models/User.php'));
    }

    public function test_dice_si_administra_segun_auth_admins(): void
    {
        $this->import();

        $model = $this->project->read('app/Models/User.php');

        $this->assertStringContainsString('public function isAdmin(): bool', $model);
        $this->assertStringContainsString("config('auth.admins', [])", $model);
    }

    public function test_un_modelo_sin_la_clave_sale_como_siempre(): void
    {
        $this->import();

        $post = $this->project->read('app/Models/Post.php');

        $this->assertStringContainsString('class Post extends Model', $post);
        $this->assertStringNotContainsString('Authenticatable', $post);
        $this->assertStringNotContainsString('isAdmin', $post);
        $this->assertStringNotContainsString('protected $hidden', $post);
    }

    public function test_el_manifiesto_anota_que_es_authenticatable(): void
    {
        $this->import();

        $manifest = json_decode($this->project->read('.larapack/manifest.json'), true);

        $this->assertTrue($manifest['models']['User']['declaration']['authenticatable'] ?? false);
        $this->assertArrayNotHasKey('declaration', $manifest['models']['Post']);
    }

    public function test_el_modelo_generado_compila(): void
    {
        $this->import();

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->project->path.'/app/Models/User.php').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_sin_correo_o_contrasena_es_un_error(): void
    {
        ['errors' => $errors] = ImportDocument::fromArray(['models' => [[
            'name' => 'Member',
            'authenticatable' => true,
            'props' => [['name' => 'name', 'type' => 'string']],
        ]]]);

        $messages = implode("\n", array_column($errors, 'message'));

        $this->assertStringContainsString('no declara `email`', $messages);
        $this->assertStringContainsString('no declara `password`', $messages);
    }

    // 9.4.0: LA AUDITORÍA DE SEGURIDAD

    /**
     * isAdmin() decide por el correo: sin índice único, dos usuarios lo
     * comparten. La tabla que crea LaraPack no lo traía.
     */
    public function test_el_correo_lleva_indice_unico_aunque_no_se_declare(): void
    {
        $this->import();

        $migration = $this->project->read((string) $this->project->glob('database/migrations/*_create_users_table.php'));

        $this->assertStringContainsString('$table->unique(\'email\');', $migration);
        $this->assertStringNotContainsString('unique', $this->project->read((string) $this->project->glob('database/migrations/*_create_posts_table.php')));
    }

    public function test_un_indice_unico_del_correo_ya_declarado_no_se_repite(): void
    {
        $this->import(['indexes' => [['columns' => ['email'], 'unique' => true, 'name' => 'users_email_key']]]);

        $migration = $this->project->read((string) $this->project->glob('database/migrations/*_create_users_table.php'));

        $this->assertSame(1, substr_count($migration, '->unique('));
        $this->assertStringContainsString('users_email_key', $migration);
    }

    /**
     * Se comparaba en minúsculas: en una base que distingue mayúsculas el
     * índice único deja convivir `Ana@x.com` con `ana@x.com`.
     */
    public function test_compara_el_correo_tal_cual_esta_guardado(): void
    {
        $this->import();

        $model = $this->project->read('app/Models/User.php');

        $this->assertStringContainsString('return in_array((string) $this->email, (array) config(\'auth.admins\', []), true);', $model);
        $this->assertStringNotContainsString('strtolower', $model);
    }

    /**
     * `creatable` y `updatable` valen true por omisión: quien pueda editar un
     * usuario se verificaba el correo. No se impone —lo decide el dueño—, se
     * avisa hasta que lo diga.
     */
    public function test_avisa_de_lo_que_no_deberia_llegar_de_un_formulario(): void
    {
        $user = fn (array $verified, array $token): array => ['models' => [[
            'name' => 'User',
            'authenticatable' => true,
            'props' => [
                ['name' => 'email', 'type' => 'string'],
                ['name' => 'password', 'type' => 'string'],
                ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true, ...$verified],
                ['name' => 'remember_token', 'type' => 'string', 'nullable' => true, ...$token],
            ],
        ]]];

        $warnings = fn (array $document): string => implode("\n", array_column(
            array_filter(ImportDocument::fromArray($document)['errors'], fn (array $finding): bool => $finding['level'] === 'warning'),
            'message'
        ));

        $open = $warnings($user([], ['creatable' => false]));

        $this->assertStringContainsString("'email_verified_at' de User se puede escribir desde la API (creatable y updatable", $open);
        $this->assertStringContainsString("'remember_token' de User se puede escribir desde la API (updatable", $open);

        // Dicho con todas las letras, en un sentido o en otro, deja de avisar.
        foreach ([
            $user(['fillable' => false], ['fillable' => false]),
            $user(['creatable' => false, 'updatable' => false], ['creatable' => true, 'updatable' => true]),
        ] as $decided) {
            $this->assertStringNotContainsString('se puede escribir desde la API', $warnings($decided));
        }

        // Y sólo en el usuario que inicia sesión.
        $plain = $user([], []);
        unset($plain['models'][0]['authenticatable']);

        $this->assertStringNotContainsString('se puede escribir desde la API', $warnings($plain));
    }
}
