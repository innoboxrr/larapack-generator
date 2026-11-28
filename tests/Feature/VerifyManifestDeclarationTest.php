<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Auditoría de seguridad de 9.4.0: el manifiesto no puede decir menos que el
 * contrato.
 *
 * `secret-exposed`, `immutable-write`, `route-not-declared` y
 * `endpoint-missing` comprueban el código contra lo que el manifiesto anota
 * de cada modelo (`declaration`), y el manifiesto es un archivo más del
 * repositorio: bastaba borrarle `"secret": ["token_hash"]` para que el
 * secreto en el Resource dejara de ser un error, con `laraimport.json`
 * intacto y `verify --strict` en verde.
 */
final class VerifyManifestDeclarationTest extends TestCase
{
    private const RESOURCE = 'src/Http/Resources/Models/ApiKeyResource.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Ledger\\'));

        file_put_contents($this->project->path.'/laraimport.json', json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']], 'routes' => ['except' => ['export']]],
            ['name' => 'Consent', 'props' => [['name' => 'accepted', 'type' => 'boolean']], 'immutable' => true],
            ['name' => 'ApiKey', 'props' => [
                ['name' => 'label', 'type' => 'string'],
                ['name' => 'token_hash', 'type' => 'string', 'secret' => true],
            ]],
        ]]));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import'), $this->lastOutput);
    }

    /**
     * @return array{0: int, 1: list<array<string, mixed>>} el código de verify --strict y sus hallazgos que no son info
     */
    private function verify(): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json', '--strict' => true]);
        $findings = array_values(array_filter(
            json_decode($this->lastOutput, true)['findings'],
            fn (array $finding): bool => $finding['level'] !== 'info'
        ));

        return [$code, $findings];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function manifest(callable $change): void
    {
        $path = $this->project->path.'/.larapack/manifest.json';

        file_put_contents($path, json_encode($change(json_decode((string) file_get_contents($path), true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function exposeTheSecret(): void
    {
        $resource = $this->project->read(self::RESOURCE);
        $this->assertStringContainsString('return [', $resource);

        file_put_contents($this->project->path.'/'.self::RESOURCE, preg_replace('/return \[/', "return ['token_hash' => \$this->token_hash] + [", $resource, 1));
    }

    public function test_recien_generado_el_manifiesto_dice_lo_que_el_contrato(): void
    {
        $this->assertSame([Command::SUCCESS, []], $this->verify());
    }

    public function test_quitar_el_secreto_del_manifiesto_no_apaga_secret_exposed(): void
    {
        $this->exposeTheSecret();

        [$code, $findings] = $this->verify();
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['secret-exposed'], array_column($findings, 'check'));

        // El ataque: el manifiesto ya no dice que la columna es secreta.
        $this->manifest(function (array $manifest): array {
            unset($manifest['models']['ApiKey']['declaration']);

            return $manifest;
        });

        [$code, $findings] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([[
            'level' => 'error',
            'check' => 'manifest-declaration',
            'model' => 'ApiKey',
            'file' => '.larapack/manifest.json',
            'message' => 'El manifiesto no anota de «ApiKey» lo mismo que declara laraimport.json: difieren las columnas `secret`. '
                .'verify comprueba el código contra lo que anota el manifiesto, así que mientras difieran no comprueba lo que el contrato declara. '
                .'Regenera con larapack:import, que lo vuelve a anotar; el manifiesto no se edita a mano.',
            'package' => null,
        ]], $findings);
    }

    public function test_cada_parte_de_la_forma_que_difiere_se_nombra(): void
    {
        $this->manifest(function (array $manifest): array {
            unset($manifest['models']['Consent']['declaration']['immutable'], $manifest['models']['Post']['declaration']['actions']);

            return $manifest;
        });

        [$code, $findings] = $this->verify();
        $declaration = array_values(array_filter($findings, fn (array $finding): bool => $finding['check'] === 'manifest-declaration'));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['Consent', 'Post'], array_column($declaration, 'model'));
        $this->assertStringContainsString('difieren `immutable`.', $declaration[0]['message']);
        $this->assertStringContainsString('difieren las acciones.', $declaration[1]['message']);
    }

    public function test_un_modelo_del_contrato_que_el_manifiesto_no_tiene_se_avisa(): void
    {
        $this->exposeTheSecret();
        $this->manifest(function (array $manifest): array {
            unset($manifest['models']['ApiKey']);

            return $manifest;
        });

        [$code, $findings] = $this->verify();
        $declaration = array_values(array_filter($findings, fn (array $finding): bool => $finding['check'] === 'manifest-declaration'));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([['warning', 'ApiKey']], array_map(fn (array $finding): array => [$finding['level'], $finding['model']], $declaration));
        $this->assertStringStartsWith('laraimport.json declara «ApiKey» y el manifiesto no lo tiene:', $declaration[0]['message']);
    }
}
