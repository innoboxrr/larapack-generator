<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * .github/scripts/ecosystem-e2e.sh y los workflows que lo corren.
 *
 * La prueba entera tarda minutos y necesita red: aquí se corre el principio
 * del script con un `composer` de mentira en el PATH, que apunta con qué
 * argumentos y con qué entorno lo llaman, y un `tar` que hace lo mismo antes
 * de llamar al de verdad. Basta para ver lo que decide el script antes de
 * instalar nada: qué laravel-setup elige, a quién le da el token y que vuelve
 * a mirar de dónde salió LaraPack.
 */
final class EcosystemE2eScriptTest extends TestCase
{
    private const TOKEN = '{"github-oauth": {"github.com": "ghs_token-de-prueba"}}';

    private string $dir;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
            $this->markTestSkipped('Hace falta bash, que es con lo que corre el workflow.');
        }

        $this->dir = sys_get_temp_dir().'/larapack-ecosystem-script-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);
        mkdir($this->dir.'/calls');
        mkdir($this->dir.'/work');

        // Cada llamada deja sus argumentos y su entorno. create-project deja
        // una aplicación mínima; show contesta según FAKE_SHOW_*; require deja
        // un installed.json con lo que diga FAKE_INSTALLED.
        $composer = <<<'BASH'
            #!/usr/bin/env bash
            n=$(ls "$FAKE_CALLS" | wc -l)
            printf '%s\n' "$@" > "$FAKE_CALLS/$n-composer.args"
            env > "$FAKE_CALLS/$n-composer.env"
            args=("$@")
            for ((i = 0; i < ${#args[@]}; i++)); do
                case "${args[$i]}" in
                    create-project)
                        app="${args[$((i + 2))]}"
                        mkdir -p "$app"
                        cp "$FAKE_ARTISAN" "$app/artisan"
                        echo DB_CONNECTION=sqlite > "$app/.env"
                        exit 0 ;;
                    show)
                        last="${args[${#args[@]} - 1]}"
                        if [ "$last" = innoboxrr/laravel-setup ]; then
                            [ -z "${FAKE_SHOW_ALL:-}" ] || echo "$FAKE_SHOW_ALL"
                        else
                            [ -n "${FAKE_SHOW_CONSTRAINED:-}" ] || exit 1
                            echo "$FAKE_SHOW_CONSTRAINED"
                        fi
                        exit 0 ;;
                    require)
                        app="$(pwd)"
                        mkdir -p "$app/vendor/composer"
                        echo "$FAKE_INSTALLED" > "$app/vendor/composer/installed.json"
                        exit 0 ;;
                esac
            done
            exit 0
            BASH;
        file_put_contents($this->dir.'/bin/composer', $composer);

        $tar = <<<'BASH'
            #!/usr/bin/env bash
            env > "$FAKE_CALLS/tar.env"
            exec "$REAL_TAR" "$@"
            BASH;
        file_put_contents($this->dir.'/bin/tar', $tar);

        // El artisan de la aplicación: apunta su entorno y, en app:install,
        // puede reinstalar LaraPack de otro origen, como haría un composer
        // update que siguiera la restricción que reescribe app:setup.
        $artisan = <<<'PHP'
            <?php
            $command = current(array_filter(array_slice($argv, 1), fn ($a) => $a[0] !== '-'));
            $env = getenv();
            file_put_contents(getenv('FAKE_CALLS')."/artisan-{$command}.env", implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($env), $env)));
            if ($command === 'app:install' && getenv('FAKE_INSTALLED_AFTER_INSTALL')) {
                file_put_contents(__DIR__.'/vendor/composer/installed.json', getenv('FAKE_INSTALLED_AFTER_INSTALL'));
            }
            PHP;
        file_put_contents($this->dir.'/bin/fake-artisan.php', $artisan);

        chmod($this->dir.'/bin/composer', 0755);
        chmod($this->dir.'/bin/tar', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
    }

    public function test_sin_restriccion_pide_laravel_setup_7_2_1_y_falla_si_no_esta_publicada(): void
    {
        [$code, , $err] = $this->runScript();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ninguna versión publicada de innoboxrr/laravel-setup cumple SETUP_CONSTRAINT=^7.2.1', $err);
        $this->assertStringContainsString('Responsable: innoboxrr/laravel-setup', $err);
        $this->assertContains('^7.2.1', $this->composerCall('show'));
    }

    public function test_setup_constraint_elige_la_version_y_avisa_si_hay_una_mas_nueva_fuera(): void
    {
        [$code, $out, $err] = $this->runScript([
            'SETUP_CONSTRAINT' => '7.2.0',
            'GITHUB_ACTIONS' => 'true',
            'FAKE_SHOW_CONSTRAINED' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_SHOW_ALL' => (string) json_encode(['versions' => ['8.0.0', '7.2.0', '8.1.0-beta1']]),
            'FAKE_INSTALLED' => $this->installed('path'),
        ]);

        $this->assertStringContainsString('laravel-setup: 7.2.0 (la última que cumple 7.2.0)', $out);
        $this->assertStringContainsString('la última laravel-setup publicada es 8.0.0, fuera de SETUP_CONSTRAINT=7.2.0', $err);
        $this->assertStringContainsString('::warning title=Ecosistema (vue)%3A laravel-setup 8.0.0 sin probar', $out);
        $this->assertContains('innoboxrr/laravel-setup:7.2.0', $this->composerCall('require'));
        $this->assertStringContainsString('LaraPack instalado desde este checkout: path 9.2.0', $out);

        // El artisan de mentira no deja laraimport.json: ahí se para.
        $this->assertSame(1, $code);
        $this->assertStringContainsString('app:setup no dejó laraimport.json', $err);
    }

    public function test_vuelve_a_mirar_el_origen_de_larapack_despues_de_app_install(): void
    {
        [$code, , $err] = $this->runScript([
            'SETUP_CONSTRAINT' => '7.2.0',
            'FAKE_SHOW_CONSTRAINED' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_SHOW_ALL' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_INSTALLED' => $this->installed('path'),
            'FAKE_INSTALLED_AFTER_INSTALL' => $this->installed('zip'),
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('después de app:install: el LaraPack instalado no es el de este checkout (zip 9.2.0', $err);
    }

    public function test_falla_si_composer_instala_larapack_de_packagist(): void
    {
        [$code, , $err] = $this->runScript([
            'SETUP_CONSTRAINT' => '7.2.0',
            'FAKE_SHOW_CONSTRAINED' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_SHOW_ALL' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_INSTALLED' => $this->installed('zip'),
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('después de composer require: el LaraPack instalado no es el de este checkout (zip 9.2.0', $err);
    }

    public function test_el_token_solo_le_llega_a_composer(): void
    {
        $this->runScript(['ECOSYSTEM_COMPOSER_AUTH' => self::TOKEN]);

        $calls = glob($this->dir.'/calls/*-composer.env') ?: [];
        $this->assertNotSame([], $calls);

        foreach ($calls as $env) {
            $lines = file($env, FILE_IGNORE_NEW_LINES) ?: [];
            $this->assertContains('COMPOSER_AUTH='.self::TOKEN, $lines, basename($env));
            $this->assertCount(1, preg_grep('/ghs_token-de-prueba/', $lines) ?: [], basename($env).': el token sólo en COMPOSER_AUTH');
        }

        // tar lo lanza el script como cualquier otra orden (npm, los tests):
        // no tiene que ver el token por ningún lado.
        $tar = (string) file_get_contents($this->dir.'/calls/tar.env');
        $this->assertStringNotContainsString('ghs_token-de-prueba', $tar);
    }

    public function test_el_token_le_llega_a_app_install_y_no_a_app_setup(): void
    {
        $this->runScript([
            'ECOSYSTEM_COMPOSER_AUTH' => self::TOKEN,
            'SETUP_CONSTRAINT' => '7.2.0',
            'FAKE_SHOW_CONSTRAINED' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_SHOW_ALL' => (string) json_encode(['versions' => ['7.2.0']]),
            'FAKE_INSTALLED' => $this->installed('path'),
        ]);

        // app:install corre composer update por dentro: necesita el token.
        $this->assertContains('COMPOSER_AUTH='.self::TOKEN, explode("\n", (string) file_get_contents($this->dir.'/calls/artisan-app:install.env')));
        $this->assertStringNotContainsString('ghs_token-de-prueba', (string) file_get_contents($this->dir.'/calls/artisan-app:setup.env'));
    }

    public function test_un_composer_auth_del_entorno_tampoco_se_hereda(): void
    {
        $this->runScript(['COMPOSER_AUTH' => self::TOKEN]);

        $this->assertStringNotContainsString('ghs_token-de-prueba', (string) file_get_contents($this->dir.'/calls/tar.env'));
        $this->assertStringContainsString('ghs_token-de-prueba', (string) file_get_contents((glob($this->dir.'/calls/*-composer.env') ?: [''])[0]));
    }

    public function test_el_workflow_corre_en_los_pr_que_cambian_version_y_no_expone_el_token(): void
    {
        $workflow = (string) file_get_contents($this->root().'/.github/workflows/ecosystem.yml');

        $paths = $this->block($workflow, 'pull_request:');
        $this->assertMatchesRegularExpression('/^\s+- VERSION$/m', $paths);
        $this->assertMatchesRegularExpression('/^\s+- \.github\/scripts\/ecosystem-e2e-checks\.php$/m', $paths);

        $this->assertStringContainsString('workflow_call:', $workflow);
        $this->assertMatchesRegularExpression('/^\s+ECOSYSTEM_COMPOSER_AUTH: /m', $workflow);
        $this->assertDoesNotMatchRegularExpression('/^\s+COMPOSER_AUTH: /m', $workflow);
    }

    public function test_release_no_etiqueta_una_version_nueva_sin_el_ecosistema(): void
    {
        $release = (string) file_get_contents($this->root().'/.github/workflows/release.yml');

        $this->assertStringContainsString('uses: ./.github/workflows/ecosystem.yml', $release);
        $this->assertMatchesRegularExpression('/needs: \[version, ecosystem\]/', $release);
        $this->assertStringContainsString("needs.ecosystem.result == 'success' || needs.ecosystem.result == 'skipped'", $release);
        $this->assertStringContainsString("if: needs.version.outputs.unpublished == 'true'", $release);
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: int, 1: string, 2: string}
     */
    private function runScript(array $env = []): array
    {
        $base = getenv();
        unset($base['GITHUB_ACTIONS'], $base['COMPOSER_AUTH'], $base['ECOSYSTEM_COMPOSER_AUTH'], $base['SETUP_CONSTRAINT'], $base['FAKE_INSTALLED_AFTER_INSTALL']);

        $env = $env + [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
            'FAKE_CALLS' => $this->dir.'/calls',
            'REAL_TAR' => trim((string) shell_exec('command -v tar')),
            'ECOSYSTEM_WORK' => $this->dir.'/work',
            'ECOSYSTEM_LARAPACK_VERSION' => '9.2.0',
            'GITHUB_WORKSPACE' => $this->root(),
            'FAKE_INSTALLED' => '',
            'FAKE_ARTISAN' => $this->dir.'/bin/fake-artisan.php',
        ] + $base;

        $process = proc_open(
            ['bash', $this->root().'/.github/scripts/ecosystem-e2e.sh', 'vue'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root(),
            $env
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @return list<string>
     */
    private function composerCall(string $command): array
    {
        foreach (glob($this->dir.'/calls/*-composer.args') ?: [] as $file) {
            $args = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            if (in_array($command, $args, true)) {
                return $args;
            }
        }

        $this->fail("No se llamó a composer {$command}.");
    }

    private function installed(string $type): string
    {
        return (string) json_encode(['packages' => [['name' => 'innoboxrr/larapack-generator', 'version' => '9.2.0', 'dist' => ['type' => $type]]]]);
    }

    /** El bloque YAML que cuelga de una clave, hasta la siguiente al mismo nivel. */
    private function block(string $yaml, string $key): string
    {
        $this->assertMatchesRegularExpression('/^( *)'.preg_quote($key, '/').'$/m', $yaml);
        preg_match('/^( *)'.preg_quote($key, '/').'\n((?:\1 +.*\n|\s*\n)*)/m', $yaml, $m);

        return $m[2] ?? '';
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
