<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use RuntimeException;

/**
 * Los ámbitos de un realineamiento: la raíz del proyecto y cada paquete
 * local que tiene contrato o manifiesto propio. Cada uno se regenera y se
 * compara por separado, con su laraimport y su manifiesto, igual que
 * `verify` mide el manifiesto de cada paquete (ProjectVerification).
 *
 * Qué es un paquete local es lo mismo que para el resto del generador
 * (ProjectSources): los repositorios `path` del composer.json y
 * `packages/*`, sólo los que están dentro de la raíz. Uno de fuera es una
 * dependencia, no parte de lo que se realinea.
 *
 * En un proyecto que no usa LaraPack (la raíz sin contrato ni manifiesto),
 * cada paquete local es su propio ámbito aunque tampoco tenga nada: su
 * `no-contract`, su código sin gestionar y sus controladores se deciden y se
 * hacen por paquete. Lo encontró una prueba de autonomía sobre un monorepo
 * de 20 paquetes sin contrato: todo salía con el ámbito de la raíz. En un
 * proyecto que sí usa LaraPack, un paquete sin nada sigue sin ser ámbito
 * (un paquete de soporte no es una decisión): su código lo mide la raíz.
 *
 * @phpstan-type Scope array{package: string|null, path: string, root: string, contract: bool, manifest: bool}
 */
final class Scopes
{
    private ProjectSources $sources;

    public function __construct(private readonly string $root)
    {
        $this->sources = new ProjectSources($root);
    }

    /**
     * @param  list<string>  $only  nombres de --package (directorio, ruta, nombre de Composer o `app`); vacío, todos
     * @return list<Scope>
     */
    public function all(array $only = []): array
    {
        $root = $this->sources->root();
        $selected = $this->resolve($only);
        $scopes = [];
        $managed = is_file($root.'/laraimport.json') || is_file($root.'/.larapack/manifest.json');

        foreach ($this->sources->roots() as $directory) {
            if ($directory !== $root && ! str_starts_with($directory, $root.'/')) {
                continue;
            }

            if ($selected !== [] && ! in_array($directory, $selected, true)) {
                continue;
            }

            $contract = is_file($directory.'/laraimport.json');
            $manifest = is_file($directory.'/.larapack/manifest.json');

            // En un proyecto con LaraPack, un paquete sin nada de LaraPack no
            // es un ámbito: su código lo mide el de la raíz (verify recorre
            // packages/*). Sin LaraPack en la raíz, cada paquete es el suyo.
            if ($directory !== $root && ! $contract && ! $manifest && $managed) {
                continue;
            }

            $scopes[] = [
                'package' => $directory === $root ? null : $this->nameOf($directory),
                'path' => $directory === $root ? '.' : substr($directory, strlen($root) + 1),
                'root' => $directory,
                'contract' => $contract,
                'manifest' => $manifest,
            ];
        }

        return $scopes;
    }

    /**
     * Las raíces de lo que se nombra en --package, para acotar. Un nombre que
     * no existe falla: acotar a nada parecería un proyecto sin desviaciones.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function resolve(array $names): array
    {
        $verification = new ProjectVerification($this->root);
        $roots = [];

        foreach ($names as $name) {
            $resolved = $verification->resolve($name);

            if ($resolved === null) {
                throw new RuntimeException("No hay un paquete local {$name}. Usa el nombre de su directorio en packages/, su ruta, su nombre de Composer o `app` para el núcleo. Hay: ".implode(', ', $verification->known()).'.');
            }

            $roots[] = $resolved;
        }

        return array_values(array_unique($roots));
    }

    /**
     * El nombre de Composer del paquete, o su ruta si no lo declara. Es el
     * mismo que pone `verify` en `package`.
     */
    public function nameOf(string $directory): string
    {
        $name = ProjectSources::composer($directory)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : substr($directory, strlen($this->sources->root()) + 1);
    }

    /**
     * Las rutas (relativas a la raíz) de los paquetes locales que son
     * ámbito: lo que hay debajo es de ellos, no de la raíz.
     *
     * @return list<string>
     */
    public function packagePaths(): array
    {
        return array_values(array_filter(array_column($this->all(), 'path'), fn (string $path): bool => $path !== '.'));
    }

    public function sources(): ProjectSources
    {
        return $this->sources;
    }
}
