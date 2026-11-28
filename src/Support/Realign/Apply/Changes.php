<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\UnifiedDiff;
use ParseError;

/**
 * Un cambio que se escribe entero o no se escribe: los archivos de una
 * corrección (el trait que se mueve y cada `use` que lo nombra; las pruebas
 * que se añaden a un test), con lo que había antes de cada uno.
 *
 * commit() escribe, pasa la Pint del proyecto por lo escrito si se pide y
 * comprueba que cada PHP siga parseando. Si uno no parsea, devuelve TODOS a
 * como estaban —uno creado se borra, uno borrado se restaura— y dice por
 * qué: un cambio a medias es peor que ninguno.
 */
final class Changes
{
    /**
     * Ruta absoluta => contenido nuevo; null, borrarlo.
     *
     * @var array<string, string|null>
     */
    private array $writes = [];

    /**
     * Ruta absoluta => lo que había; null, no existía.
     *
     * @var array<string, string|null>
     */
    private array $originals = [];

    public function __construct(private readonly string $projectRoot) {}

    public function put(string $file, string $content): void
    {
        $this->remember($file);
        $this->writes[$file] = $content;
    }

    public function delete(string $file): void
    {
        $this->remember($file);
        $this->writes[$file] = null;
    }

    public function isEmpty(): bool
    {
        return $this->writes === [];
    }

    /**
     * @return list<string> rutas absolutas
     */
    public function files(): array
    {
        return array_keys($this->writes);
    }

    /**
     * El diff unificado de todo el cambio, para --dry-run.
     */
    public function diff(): string
    {
        $diffs = [];

        foreach ($this->writes as $file => $content) {
            $diffs[] = UnifiedDiff::of($this->originals[$file] ?? '', $content ?? '', $this->relative($file));
        }

        return implode('', $diffs);
    }

    /**
     * El diff de lo que quedó en disco después de commit(), con la Pint ya
     * pasada: lo que cambió de verdad. Es el de la simulación (Simulation),
     * que escribe en una copia.
     */
    public function appliedDiff(): string
    {
        $diffs = [];

        foreach (array_keys($this->writes) as $file) {
            $diffs[] = UnifiedDiff::of($this->originals[$file] ?? '', is_file($file) ? Files::read($file) : '', $this->relative($file));
        }

        return implode('', $diffs);
    }

    /**
     * Escribe. Con `$format`, pasa la Pint del proyecto por los PHP escritos
     * (y vuelve a anotar el hash de lo registrado sin editar en `$manifest`).
     *
     * @return string|null null si se escribió; la razón, si se devolvió todo a como estaba
     */
    public function commit(bool $format = false, ?Manifest $manifest = null): ?string
    {
        foreach ($this->writes as $file => $content) {
            if ($content === null) {
                if (is_file($file)) {
                    unlink($file);
                }

                continue;
            }

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            file_put_contents($file, $content);
        }

        $written = array_keys(array_filter($this->writes, fn (?string $content): bool => $content !== null));

        if ($format && $written !== [] && Formatter::pint() !== null) {
            Formatter::format($written, $manifest ?? new Manifest($this->projectRoot));
        }

        foreach ($written as $file) {
            $problem = str_ends_with($file, '.php') ? self::parseProblem(Files::read($file)) : null;

            if ($problem !== null) {
                $this->revert();

                return "{$this->relative($file)} no parsearía ({$problem}): no se escribió nada de este cambio.";
            }
        }

        return null;
    }

    /**
     * Por qué un código PHP no parsea, o null si parsea.
     */
    public static function parseProblem(string $code): ?string
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            return $e->getMessage().', línea '.$e->getLine();
        }

        return ($tokens[0][0] ?? null) === T_OPEN_TAG ? null : 'no empieza con <?php';
    }

    /**
     * Devuelve todo a como estaba antes de commit(): para quien comprueba
     * algo más que el parseo después de escribir (ConcernMove).
     */
    public function revert(): void
    {
        foreach ($this->originals as $file => $original) {
            if ($original === null) {
                if (is_file($file)) {
                    unlink($file);
                }

                continue;
            }

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            file_put_contents($file, $original);
        }
    }

    private function remember(string $file): void
    {
        if (! array_key_exists($file, $this->originals)) {
            $this->originals[$file] = is_file($file) ? Files::read($file) : null;
        }
    }

    private function relative(string $file): string
    {
        $root = rtrim($this->projectRoot, '/').'/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
