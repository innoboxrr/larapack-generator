<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use Innoboxrr\LarapackGenerator\Support\OwnerTerminal;

/**
 * La terminal del dueño en una prueba en proceso: guarda lo que se le enseña
 * y contesta lo que se le diga, o, sin respuesta fija, la frase que pide la
 * pregunta (lo que escribiría el dueño que lo aprueba).
 */
final class FakeOwnerTerminal extends OwnerTerminal
{
    public string $shown = '';

    public function __construct(private readonly ?string $answer = null) {}

    public function write(string $text): void
    {
        $this->shown .= $text;
    }

    public function readLine(): ?string
    {
        if ($this->answer !== null) {
            return $this->answer;
        }

        return preg_match_all('/escribe «([^»]+)»/u', $this->shown, $match) > 0 ? end($match[1]) : null;
    }
}
