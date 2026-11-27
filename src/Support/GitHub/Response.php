<?php

namespace Innoboxrr\LarapackGenerator\Support\GitHub;

/**
 * Lo que respondió GitHub, o por qué no respondió.
 *
 * `status` es null cuando no hubo respuesta HTTP —sin red, sin `gh`, un
 * tiempo agotado—: eso no es un 404 y nunca se lee como «no hay reglas».
 */
final class Response
{
    public function __construct(
        public readonly ?int $status,
        public readonly mixed $json = null,
        public readonly ?string $error = null,
    ) {}

    public static function failure(string $error): self
    {
        return new self(null, null, $error);
    }

    /**
     * Una respuesta HTTP con su cuerpo tal como llegó.
     */
    public static function fromBody(int $status, string $body): self
    {
        return new self($status, trim($body) === '' ? null : json_decode($body, true));
    }

    public function ok(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * El porqué de una respuesta que no es 2xx, en una línea.
     */
    public function describe(): string
    {
        if ($this->status === null) {
            return $this->error ?? 'sin respuesta';
        }

        $message = is_array($this->json) && is_string($this->json['message'] ?? null) ? ': '.$this->json['message'] : '';

        return "HTTP {$this->status}{$message}";
    }
}
