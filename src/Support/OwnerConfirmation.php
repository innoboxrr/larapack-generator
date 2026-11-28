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
 *  - **Sin terminal, nombrándose** (CI de aprobación, una persona en un
 *    entorno sin tty): la variable de entorno `LARAPACK_APPROVER` con su
 *    nombre **y** la opción `--approver` con el mismo nombre. El nombre queda
 *    en cada entrada del candado (`approved_by`), a la vista en el diff que
 *    revisa CODEOWNERS. Hacen falta las dos para que no se use por
 *    accidente: una variable exportada en el perfil de la shell la hereda el
 *    agente que se lanza desde ella, pero la opción hay que escribirla en la
 *    orden, y la guardia de los agentes bloquea cualquier orden que la lleve o
 *    que ponga la variable (Guard::approvesHandRolled()).
 *
 * Por qué no basta «hay una terminal»: un agente que corre dentro de la
 * terminal de una persona puede abrir /dev/tty, pero entonces quien lee la
 * pregunta y escribe la frase es esa persona. Lo que no puede un agente es
 * contestarla él: no escribe en una terminal que no es la suya.
 *
 * En Windows no hay /dev/tty. Se usa la consola, si la entrada estándar lo
 * es (cmd, PowerShell, Windows Terminal); desde Git Bash (mintty) la entrada
 * es una tubería y no hay forma fiable de saber si hay alguien delante: el
 * comando lo dice y la persona usa `LARAPACK_APPROVER` + `--approver`, o lo
 * corre desde PowerShell o con `winpty`.
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
     * El nombre de quien aprueba sin terminal, si lo dio como se pide: la
     * variable y la opción, iguales. null si no se dio `--approver`.
     *
     * @return array{approver: string|null, error: string|null}
     */
    public static function namedApprover(?string $option): array
    {
        if ($option === null) {
            return ['approver' => null, 'error' => null];
        }

        $option = self::clean($option);
        $env = getenv(self::APPROVER_ENV);
        $env = is_string($env) ? self::clean($env) : '';

        if ($option === '') {
            return ['approver' => null, 'error' => '--approver necesita el nombre de la persona que aprueba.'];
        }

        if ($env === '') {
            return ['approver' => null, 'error' => '--approver sólo vale con la variable de entorno '.self::APPROVER_ENV.' con el mismo nombre: es la vía de una persona sin terminal (una CI de aprobación), no un atajo.'];
        }

        if ($env !== $option) {
            return ['approver' => null, 'error' => '--approver no coincide con '.self::APPROVER_ENV.'.'];
        }

        return ['approver' => $option, 'error' => null];
    }

    /**
     * La frase que se escribe para confirmar: el verbo y cuántas.
     */
    public static function phrase(string $action, int $count): string
    {
        return ($action === 'approve' ? 'apruebo' : 'retiro').' '.$count;
    }

    /**
     * Un nombre en una línea, sin caracteres de control.
     */
    private static function clean(string $name): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', mb_scrub($name, 'UTF-8')));
    }
}
