<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * La terminal en la que una persona confirma un cambio del candado
 * (OwnerConfirmation): lo que se le enseña y la línea que escribe.
 */
class OwnerTerminal
{
    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function __construct(private $in, private $out) {}

    public function write(string $text): void
    {
        fwrite($this->out, $text);
        fflush($this->out);
    }

    /**
     * La línea que escribe la persona, sin el salto; null si no escribe nada
     * (fin de la entrada).
     */
    public function readLine(): ?string
    {
        $line = fgets($this->in);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
