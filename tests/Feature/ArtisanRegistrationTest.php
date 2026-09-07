<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Providers\GeneratorServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Hasta ahora el provider solo publicaba la config: los comandos existian
 * pero `php artisan` no los veia, y la unica via era el binario `builder`.
 */
final class ArtisanRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GeneratorServiceProvider::class];
    }

    public function test_artisan_registra_los_comandos_del_generador(): void
    {
        $registered = array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all());

        foreach ([
            'larapack:import',
            'larapack:full-model',
            'larapack:model',
            'larapack:migration',
            'larapack:requests',
            'larapack:providers',
            'larapack:remove-full-model',
        ] as $command) {
            $this->assertContains($command, $registered, "Artisan no registro {$command}.");
        }
    }

    public function test_no_sobrescribe_los_comandos_make_de_laravel(): void
    {
        $kernel = $this->app[\Illuminate\Contracts\Console\Kernel::class];

        foreach (['make:model', 'make:policy', 'make:factory', 'make:observer', 'make:migration'] as $command) {
            $this->assertStringStartsWith(
                'Illuminate\\',
                $kernel->all()[$command]::class,
                "El generador sobrescribio el comando {$command} de Laravel."
            );
        }
    }

    public function test_publica_la_configuracion(): void
    {
        $this->assertSame('UTC', config('larapack-generator.timezone'));
    }
}
