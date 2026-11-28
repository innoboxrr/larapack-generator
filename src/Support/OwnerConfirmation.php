<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Closure;

/**
 * Quién confirma un cambio de `.larapack/hand-rolled.lock`: una persona.
 *
 * Hasta 9.4.0 `larapack:hand-rolled approve` no preguntaba nada y confiaba en
 * tres capas de fuera —la guardia de los agentes, el hook de git y
 * CODEOWNERS—. En un proyecto sin la guardia ni el hook instalados, un agente
 * aprobaba sus propias excepciones con `approve --all` y la entrada en
 * /dev/null. Ahora el propio comando exige a una persona, de una de dos
 * formas:
 *
 *  - **En su terminal** (lo normal): el comando le enseña lo que va a
 *    aprobar o retirar —el objetivo, la razón y lo que cubre (el hallazgo)—
 *    y ella escribe la frase (`apruebo 3`, `retiro 1`) con el número de
 *    excepciones, para que no se confirme a ciegas. La pregunta va a la
 *    terminal y no a la entrada estándar: una tubería (`yes | …`) no la
 *    contesta. Es la misma regla que el hook de git (GitHooks).
 *  - **Sin terminal, en el workflow de aprobación** (NamedApproval): una
 *    persona lo lanza a mano en GitHub Actions (`larapack:ci --approval` lo
 *    genera), y queda en cada entrada del candado su cuenta (`approved_by`)
 *    y la corrida (`approved_run`), a la vista en el diff que revisa
 *    CODEOWNERS y comprobadas con GitHub en el job de la puerta. Hasta 9.4.0
 *    bastaba con `LARAPACK_APPROVER` y `--approver` en cualquier sitio, y un
 *    agente sin la guardia se aprobó así sus propias excepciones. La guardia
 *    de los agentes sigue bloqueando cualquier orden con `--approver` o que
 *    dé valor a la variable (Guard::approvesHandRolled()).
 *
 * Lo que esto NO garantiza, dicho: un proceso sin terminal puede fabricarse
 * una (`pty.spawn` de Python, `script -c`, `expect`, `unbuffer`), y desde ahí
 * escribir él la frase. La pregunta en /dev/tty frena el error y la tubería
 * (`yes | …`), no a un agente decidido. Por eso la guardia de los agentes
 * trata como aprobación cualquier orden que pase `hand-rolled approve|revoke`
 * por uno de esos programas (Guard::approvesHandRolled()), y la capa que no
 * se puede saltar sigue siendo la de fuera del proceso: CODEOWNERS sobre el
 * candado y la revisión de su diff en el PR, que la CI exige.
 *
 * En Windows no hay /dev/tty. Se usa la consola, si la entrada estándar lo
 * es (cmd, PowerShell, Windows Terminal); desde Git Bash (mintty) la entrada
 * es una tubería y no hay forma fiable de saber si hay alguien delante: el
 * comando lo dice y la persona lo corre desde PowerShell o con `winpty`.
 */
final class OwnerConfirmation
{
    public const APPROVER_ENV = 'LARAPACK_APPROVER';

    /**
     * Para las pruebas: qué terminal abre openTerminal(). null, la de verdad.
     *
     * @var (Closure(): ?OwnerTerminal)|null
     */
    private static ?Closure $opener = null;

    /**
     * @param  (Closure(): ?OwnerTerminal)|null  $opener
     */
    public static function useTerminal(?Closure $opener): void
    {
        self::$opener = $opener;
    }

    /**
     * La terminal de la persona que corre el comando, o null si no la hay.
     */
    public static function openTerminal(): ?OwnerTerminal
    {
        if (self::$opener !== null) {
            return (self::$opener)();
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return defined('STDIN') && defined('STDERR') && @stream_isatty(STDIN) ? new OwnerTerminal(STDIN, STDERR) : null;
        }

        // Sin terminal de control (un proceso lanzado con setsid, un agente,
        // la CI) abrir /dev/tty falla con ENXIO.
        $in = @fopen('/dev/tty', 'r');

        if ($in === false) {
            return null;
        }

        $out = @fopen('/dev/tty', 'w');

        if ($out === false) {
            fclose($in);

            return null;
        }

        return new OwnerTerminal($in, $out);
    }

    /**
     * Quién aprueba sin terminal, si se dio como se pide: dentro del workflow
     * de aprobación, lanzado a mano por esa persona (NamedApproval). null si
     * no se dio `--approver`.
     *
     * @return array{approver: string|null, run: string|null, error: string|null}
     */
    public static function namedApprover(?string $option): array
    {
        return NamedApproval::fromEnvironment($option);
    }

    /**
     * La frase que se escribe para confirmar: el verbo y cuántas.
     */
    public static function phrase(string $action, int $count): string
    {
        return ($action === 'approve' ? 'apruebo' : 'retiro').' '.$count;
    }
}
