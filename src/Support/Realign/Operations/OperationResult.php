<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

/**
 * Lo que hizo una operación, archivo por archivo, en las tres listas de
 * siempre (las mismas que `larapack:fix`):
 *
 *  - `fixed`: lo que cambió (o cambiaría, con `--dry-run`).
 *  - `skipped`: lo que no se tocó porque no se reconoció con certeza, con
 *    la razón. Es trabajo que queda para una persona o un agente, y por eso
 *    hace que el comando termine con un código distinto de cero.
 *  - `unchanged`: lo que ya estaba bien.
 *
 * Cada elemento es `{file, reason}`, con la ruta relativa a la raíz del
 * proyecto, más lo que la operación quiera añadir (`diff` en una
 * simulación, `step`).
 *
 * @phpstan-type Item array{file: string|null, reason: string}&array<string, mixed>
 */
final class OperationResult
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $fixed = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $skipped = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $unchanged = [];

    /**
     * @var list<string>
     */
    private array $notes = [];

    /**
     * @param  array<string, mixed>  $extra
     */
    public function fixed(?string $file, string $reason, array $extra = []): self
    {
        $this->fixed[] = ['file' => $file, 'reason' => $reason] + $extra;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function skipped(?string $file, string $reason, array $extra = []): self
    {
        $this->skipped[] = ['file' => $file, 'reason' => $reason] + $extra;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function unchanged(?string $file, string $reason, array $extra = []): self
    {
        $this->unchanged[] = ['file' => $file, 'reason' => $reason] + $extra;

        return $this;
    }

    /**
     * Algo que decir que no es de un archivo: una advertencia, lo que falta
     * por hacer a mano.
     */
    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /**
     * Suma lo que hizo otra operación (la que delega, como `apply` en
     * `adopt`).
     */
    public function merge(self $other): self
    {
        $this->fixed = [...$this->fixed, ...$other->fixed];
        $this->skipped = [...$this->skipped, ...$other->skipped];
        $this->unchanged = [...$this->unchanged, ...$other->unchanged];
        $this->notes = [...$this->notes, ...$other->notes];

        return $this;
    }

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }

    /**
     * @return array{fixed: list<array<string, mixed>>, skipped: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, notes: list<string>}
     */
    public function toArray(): array
    {
        return ['fixed' => $this->fixed, 'skipped' => $this->skipped, 'unchanged' => $this->unchanged, 'notes' => $this->notes];
    }
}
