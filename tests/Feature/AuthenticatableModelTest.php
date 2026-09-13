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
}
