<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las órdenes que se saltan el hook de git, leídas como las lee la shell.
 *
 * El hook de git es la capa que no depende del agente, y estas órdenes son
 * la forma de quitarla sin tocar un archivo. Lo que no se la salta —leer la
 * configuración, un mensaje que dice `-n`— no puede frenarse: un hook que
 * frena `git commit -m "fix -n"` acaba quitado.
 */
final class GitBypassTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function bypasses(string $command): array
    {
        return Guard::bypassesGitHooks(['tool_input' => ['command' => $command]]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bypassing(): array
    {
        return [
            'commit --no-verify' => ['git commit --no-verify -m wip', 'git commit --no-verify'],
            'commit abreviado' => ['git commit --no-veri -m wip', 'git commit --no-verify'],
            'commit -n' => ['git commit -n -m wip', 'git commit --no-verify'],
            'commit -n en un grupo' => ['git commit -anm wip', 'git commit --no-verify'],
            'commit -n tras -m aparte' => ['git commit -m "uno" -n', 'git commit --no-verify'],
            'push --no-verify' => ['git push --no-verify origin main', 'git push --no-verify'],
            'merge --no-verify' => ['git merge --no-verify feature', 'git merge --no-verify'],
            'config core.hooksPath' => ['git config core.hooksPath /dev/null', 'git config core.hooksPath'],
            'config --unset' => ['git config --unset core.hooksPath', 'git config core.hooksPath'],
            'config sin distinguir mayúsculas' => ['git config --local CORE.HOOKSPATH x', 'git config core.hooksPath'],
            '-c core.hooksPath' => ['git -c core.hooksPath=/dev/null commit -m x', 'git -c core.hooksPath'],
            '--config-env' => ['git --config-env=core.hooksPath=H commit -m x', 'git -c core.hooksPath'],
            'con -C delante' => ['git -C packages/x commit --no-verify -m x', 'git commit --no-verify'],
            'con variables y env delante' => ['GIT_EDITOR=true env git commit -n', 'git commit --no-verify'],
            'git.exe entre comillas' => ["'C:\\Program Files\\Git\\bin\\git.exe' commit --no-verify", 'git commit --no-verify'],
            'tras otra orden' => ['vendor/bin/pint && git commit --no-verify -m x', 'git commit --no-verify'],
            'dentro de bash -c' => ['bash -c "git commit --no-verify -m x"', 'git commit --no-verify'],
            'con comillas en medio' => ["git commit --no-ver''ify", 'git commit --no-verify'],
            'GIT.EXE' => ['GIT.EXE commit -n', 'git commit --no-verify'],
            '-c de otra cosa delante' => ['git -c user.name=x commit -n', 'git commit --no-verify'],
            '--config-env de otra cosa delante' => ['git --config-env=user.name=U commit -n', 'git commit --no-verify'],
            'opción global sin valor delante' => ['git --no-pager commit -n', 'git commit --no-verify'],
            '-n tras -a' => ['git commit -a -n', 'git commit --no-verify'],
            '-n tras -m con el valor pegado' => ['git commit -mwip -n', 'git commit --no-verify'],
            'tres shells anidadas' => ['bash -c "bash -c \\"bash -c \'git commit -n\'\\""', 'git commit --no-verify'],
        ];
    }

    #[DataProvider('bypassing')]
    public function test_reconoce_lo_que_se_salta_el_hook_de_git(string $command, string $expected): void
    {
        $this->assertSame([$expected], self::bypasses($command));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function harmless(): array
    {
        return [
            'commit normal' => ['git commit -m wip'],
            'el mensaje dice -n' => ['git commit -m "-n no es una opción aquí"'],
            '-mn es el mensaje «n»' => ['git commit -mn'],
            '-n tras -- es una ruta' => ['git commit -m x -- -n'],
            'push -n es un ensayo' => ['git push -n origin main'],
            'push --no-verbose' => ['git push --no-ve origin main'],
            'leer core.hooksPath' => ['git config --get core.hooksPath'],
            'leer con get' => ['git config get core.hooksPath'],
            'listar' => ['git config --list --show-origin'],
            'otra opción de config' => ['git config core.autocrlf false'],
            'no es git' => ['echo git commit --no-verify'],
            'grep' => ['grep -r "no-verify" docs/'],
            'heredoc a cat' => ["cat <<'EOF'\ngit commit --no-verify\nEOF"],
            'otro programa con =' => ['1X=1 git commit --no-verify'],
            'otro control de versiones' => ['svn commit --no-verify'],
            'subcomando en mayúsculas' => ['git COMMIT --no-verify'],
            'el valor de -C no es el subcomando' => ['git -C commit status -n'],
            'una opción larga con n' => ['git commit --no-edit -m x'],
            'una ruta con -n' => ['git commit file-n.txt -m x'],
            'una palabra que termina en n' => ['git commit xn -m x'],
            '-S con su clave pegada' => ['git commit -Sn -m x'],
            '-u con su modo pegado' => ['git commit -uno -m x'],
            'el mensaje es -n' => ['git commit -m -n'],
            'otra clave de config' => ['git config xcore.hooksPath y'],
        ];
    }

    #[DataProvider('harmless')]
    public function test_no_frena_lo_que_no_se_lo_salta(string $command): void
    {
        $this->assertSame([], self::bypasses($command));
    }

    public function test_cada_orden_se_dice_una_vez_y_en_orden(): void
    {
        $this->assertSame(
            ['git commit --no-verify', 'git push --no-verify'],
            self::bypasses('git commit -n && git commit --no-verify && git push --no-verify')
        );
    }

    /**
     * Pasado el límite de shells anidadas no se lee: lo frena la aprobación,
     * que en ese caso falla cerrada (Guard::approvesHandRolled()).
     */
    public function test_mas_alla_del_limite_de_shells_lo_frena_la_aprobacion(): void
    {
        $command = 'bash -c "bash -c \\"bash -c \'bash -c git\\\\ commit\\\\ -n\'\\""';

        $this->assertSame([], self::bypasses($command));
        $this->assertTrue(Guard::approvesHandRolled(['tool_input' => ['command' => $command]]));
    }

    public function test_sin_orden_de_bash_no_hay_nada_que_leer(): void
    {
        $this->assertSame([], Guard::bypassesGitHooks(['tool_input' => ['file_path' => 'x.php']]));
        $this->assertSame([], Guard::bypassesGitHooks([]));
    }

    public function test_el_mensaje_dice_que_no_y_por_qué(): void
    {
        $message = Guard::bypassMessage(['git commit --no-verify']);

        $this->assertStringContainsString('`git commit --no-verify` se salta el hook de git', $message);
        $this->assertStringContainsString('core.hooksPath', $message);
        $this->assertStringContainsString('La CI comprueba lo mismo', $message);
        $this->assertStringContainsString("\nComitea sin saltártelo: si el hook te frena", $message);
        $this->assertStringEndsWith('así que saltárselo sólo retrasa el rechazo.', $message);
    }
}
