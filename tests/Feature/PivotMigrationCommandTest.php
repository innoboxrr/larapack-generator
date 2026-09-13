<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:pivot-migration` importaba la herramienta desde un namespace que no
 * existe y terminaba en "class not found" nada más ejecutarse. Nadie lo notó
 * porque el import genera las pivotes por su cuenta; lo encontró Larastan.
 */
final class PivotMigrationCommandTest extends TestCase
{
    public function test_crea_la_migracion_de_la_pivote(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:pivot-migration', ['name' => 'post_tag']), $this->lastOutput);

        $this->assertNotNull($this->project->glob('database/migrations/*_create_post_tag_table.php'));
    }
}
