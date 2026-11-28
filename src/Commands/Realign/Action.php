<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Commands\Concerns\FormatsJson;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Una acción de `larapack:realign`: lee lo que pidió la línea de órdenes,
 * llama a lo que la hace (src/Support/Realign) y lo cuenta, en texto o en
 * JSON.
 *
 * RealignCommand valida lo común —la acción existe, sus opciones y sus
 * argumentos son los que declara RealignActions— y entrega aquí. Una acción
 * no decide nada del realineamiento: qué paso sigue, si uno está hecho o
 * qué falta para terminar lo dicen Planner, Navigation, StepCheck,
 * DoneWhen y Completion, que se prueban sin consola.
 *
 * Una acción nueva: su clase aquí, su entrada en RealignCommand::HANDLERS y
 * su descripción en RealignActions::ALL.
 */
abstract class Action
{
    use FormatsJson;

    /**
     * @param  string  $root  la raíz del proyecto; vacía en las acciones que no lo necesitan (RealignActions::WITHOUT_PROJECT)
     * @param  string  $name  la acción tal como se pidió: una clase puede servir a varias (defer y undefer; las operaciones)
     */
    final public function __construct(
        protected readonly InputInterface $input,
        protected readonly OutputInterface $output,
        protected readonly string $root,
        protected readonly string $name,
    ) {}

    /**
     * @param  list<string>  $targets  los argumentos tras la acción
     * @return int el código de salida del comando
     */
    abstract public function run(array $targets): int;

    protected function wantsJson(): bool
    {
        return $this->jsonRequested($this->input);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function json(array $document): void
    {
        $this->writeJson($this->output, $document);
    }

    /**
     * Un error de la acción. En JSON lleva siempre `format_version` y
     * `action`, como documenta docs/realign/plan.md: quien lo lee sabe qué
     * falló sin mirar qué había pedido.
     */
    protected function fail(string $error, ?string $hint = null): int
    {
        return $this->reportFailure($this->input, $this->output, $error, $hint, self::failureContext($this->name));
    }

    /**
     * @return array{format_version: int, action: string}
     */
    public static function failureContext(string $action): array
    {
        return ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action];
    }

    protected function state(): RealignState
    {
        return new RealignState($this->root);
    }

    /**
     * Lo de `--package`, como lo escribió quien lo pide.
     *
     * @return list<string>
     */
    protected function packages(): array
    {
        return array_values(array_map('strval', (array) $this->input->getOption('package')));
    }

    /**
     * Las rutas de ámbito de `--package` (`.` para el núcleo); vacío, todas.
     *
     * @return list<string>
     */
    protected function scopePaths(): array
    {
        $packages = $this->packages();

        if ($packages === []) {
            return [];
        }

        $root = rtrim($this->root, '/');

        return array_map(
            fn (string $directory): string => $directory === $root ? '.' : substr($directory, strlen($root) + 1),
            (new Scopes($root))->resolve($packages)
        );
    }

    protected function relative(string $path): string
    {
        return str_starts_with($path, $this->root.'/') ? substr($path, strlen($this->root) + 1) : $path;
    }
}
