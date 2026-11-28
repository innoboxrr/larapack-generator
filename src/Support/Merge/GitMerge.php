<?php

namespace Innoboxrr\LarapackGenerator\Support\Merge;

/**
 * Lo que `larapack:reconcile` necesita saber de git: si hay una fusión —en
 * curso o recién hecha—, qué había en cada rama y qué quedó sin resolver.
 *
 * Sólo lee, salvo `stage()` y `remove()`, que marcan como resuelto lo que la
 * reconciliación resolvió. Nunca comitea, nunca cambia de rama.
 *
 * @phpstan-type Sides array{ours: string, theirs: string, base: string|null, inProgress: bool, kind: string}
 */
final class GitMerge
{
    private string $top;

    private function __construct(string $top)
    {
        $this->top = rtrim(str_replace('\\', '/', $top), '/');
    }

    /**
     * El repositorio que contiene `$directory`, o null si no hay git o no es
     * un repositorio.
     */
    public static function at(string $directory): ?self
    {
        $top = self::run($directory, ['rev-parse', '--show-toplevel']);

        return $top === null || trim($top) === '' ? null : new self(trim($top));
    }

    public function top(): string
    {
        return $this->top;
    }

    /**
     * Las dos ramas de la fusión y su ancestro común.
     *
     * En curso: un `merge`, un `rebase` o un `cherry-pick` parados (HEAD es
     * «lo nuestro», y lo otro lo dice MERGE_HEAD, REBASE_HEAD o
     * CHERRY_PICK_HEAD). Si no hay ninguna en curso y el último commit es una
     * fusión de dos padres, son esos dos: es lo que ve la CI de un pull
     * request. Una fusión de más de dos ramas a la vez (octopus) no se
     * reconcilia: se fusionan de una en una.
     *
     * @return Sides|null
     */
    public function sides(): ?array
    {
        foreach (['MERGE_HEAD' => 'merge', 'REBASE_HEAD' => 'rebase', 'CHERRY_PICK_HEAD' => 'cherry-pick'] as $ref => $kind) {
            $theirs = $this->resolve($ref);

            if ($theirs === null) {
                continue;
            }

            $ours = $this->resolve('HEAD');

            if ($ours === null) {
                return null;
            }

            // En un rebase o un cherry-pick lo que se aplica es UN commit: su
            // base es su padre, no el ancestro común de las dos ramas.
            $base = $kind === 'merge' ? $this->mergeBase($ours, $theirs) : $this->resolve($theirs.'^');

            return ['ours' => $ours, 'theirs' => $theirs, 'base' => $base, 'inProgress' => true, 'kind' => $kind];
        }

        $parents = preg_split('/\s+/', trim((string) self::run($this->top, ['rev-list', '--parents', '-n', '1', 'HEAD']))) ?: [];

        if (count($parents) !== 3) {
            return null;
        }

        return ['ours' => $parents[1], 'theirs' => $parents[2], 'base' => $this->mergeBase($parents[1], $parents[2]), 'inProgress' => false, 'kind' => 'merge'];
    }

    private function resolve(string $ref): ?string
    {
        $hash = self::run($this->top, ['rev-parse', '-q', '--verify', $ref.'^{commit}']);

        return $hash === null || trim($hash) === '' ? null : trim($hash);
    }

    private function mergeBase(string $ours, string $theirs): ?string
    {
        $base = self::run($this->top, ['merge-base', $ours, $theirs]);

        return $base === null || trim($base) === '' ? null : trim($base);
    }

    /**
     * El contenido de un archivo en un commit, o null si no existía.
     */
    public function show(?string $commit, string $absolute): ?string
    {
        return $commit === null ? null : self::run($this->top, ['show', $commit.':'.$this->relative($absolute)]);
    }

    /**
     * Si un archivo existía en un commit.
     */
    public function has(?string $commit, string $absolute): bool
    {
        return $commit !== null && self::run($this->top, ['cat-file', '-e', $commit.':'.$this->relative($absolute)]) !== null;
    }

    /**
     * Los archivos con conflicto sin resolver en el índice, en ruta absoluta.
     *
     * @return list<string>
     */
    public function unmerged(): array
    {
        $output = self::run($this->top, ['diff', '--name-only', '--diff-filter=U', '-z']);

        return array_values(array_map(
            fn (string $path): string => $this->top.'/'.$path,
            array_filter(explode("\0", (string) $output), fn (string $path): bool => $path !== '')
        ));
    }

    /**
     * Marca como resueltos (o añade al índice) estos archivos.
     *
     * @param  list<string>  $absolute
     */
    public function stage(array $absolute): void
    {
        foreach (array_chunk($absolute, 200) as $chunk) {
            self::run($this->top, ['add', '--', ...array_map($this->relative(...), $chunk)]);
        }
    }

    /**
     * Quita un archivo del árbol y del índice.
     */
    public function remove(string $absolute): void
    {
        @unlink($absolute);
        self::run($this->top, ['rm', '-q', '--cached', '--ignore-unmatch', '--', $this->relative($absolute)]);
    }

    /**
     * Lo que da `git merge-file` con las tres versiones de un archivo, si se
     * fusionan sin conflicto; null si chocan o no se pudo. Sirve para saber
     * si lo que hay en disco es exactamente la mezcla que hizo git, sin que
     * nadie la haya tocado después.
     */
    public function cleanMerge(string $ours, ?string $base, string $theirs): ?string
    {
        $directory = sys_get_temp_dir().'/larapack-merge-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0700, true)) {
            return null;
        }

        try {
            foreach (['ours' => $ours, 'base' => $base ?? '', 'theirs' => $theirs] as $name => $content) {
                file_put_contents($directory.'/'.$name, $content);
            }

            return self::run($directory, ['merge-file', '-p', '-q', 'ours', 'base', 'theirs'], false);
        } finally {
            foreach (['ours', 'base', 'theirs'] as $name) {
                @unlink($directory.'/'.$name);
            }

            @rmdir($directory);
        }
    }

    private function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return str_starts_with($absolute, $this->top.'/') ? substr($absolute, strlen($this->top) + 1) : $absolute;
    }

    /**
     * La salida de git, o null si falla o no está.
     *
     * @param  list<string>  $arguments
     */
    private static function run(string $directory, array $arguments, bool $repository = true): ?string
    {
        // `merge-file` no necesita repositorio: se corre en el directorio, sin -C.
        $process = @proc_open(
            $repository ? ['git', '-C', $directory, ...$arguments] : ['git', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repository ? null : $directory
        );

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($output) ? $output : null;
    }
}
