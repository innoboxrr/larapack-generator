<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

/**
 * Añade claves a objetos de un JSON escrito a mano sin reescribirlo: se
 * insertan en el texto, antes de la llave que cierra cada objeto, con la
 * sangría de la clave anterior (o en la misma línea si el objeto está en
 * una sola). Así un `laraimport.json` cambia sólo en las líneas que cambian,
 * y quien lo revisa ve el diff que espera, no el archivo entero reformateado.
 *
 * Se localizan los objetos por su camino (`models.3.props.5`) con un
 * recorrido del texto que respeta las cadenas y sus escapes. Si el texto no
 * es el JSON que se espera, at() devuelve null y no se toca nada.
 */
final class JsonEdit
{
    /**
     * Camino => posición de la `}` que cierra el objeto.
     *
     * @var array<string, int>
     */
    private array $closes = [];

    private int $i = 0;

    private function __construct(private string $json) {}

    public static function of(string $json): ?self
    {
        $edit = new self($json);

        try {
            $edit->skip();
            $edit->value('');
            $edit->skip();
        } catch (\RuntimeException) {
            return null;
        }

        return $edit->i === strlen($json) ? $edit : null;
    }

    /**
     * Añade claves (que el objeto no tiene) a los objetos de esos caminos.
     *
     * @param  array<string, array<string, string>>  $additions  camino => [clave => valor]
     * @return string|null el JSON nuevo, o null si algún camino no es un objeto
     */
    public function add(array $additions): ?string
    {
        $positions = [];

        foreach ($additions as $path => $pairs) {
            if (! isset($this->closes[$path])) {
                return null;
            }

            $positions[$this->closes[$path]] = $pairs;
        }

        krsort($positions);
        $json = $this->json;

        foreach ($positions as $close => $pairs) {
            $before = rtrim(substr($json, 0, $close));
            $empty = str_ends_with($before, '{');
            $multiline = str_contains(substr($json, strlen($before), $close - strlen($before)), "\n");
            $lineStart = strrpos($before, "\n");
            // La sangría de la última línea del último valor: la de su clave.
            $indent = $lineStart === false ? '' : (string) preg_replace('/^([ \t]*).*$/s', '$1', substr($before, $lineStart + 1));
            $encoded = array_map(
                fn (string $key, string $value): string => json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                array_map('strval', array_keys($pairs)),
                $pairs
            );

            $insertion = $multiline && ! $empty
                ? ",\n".$indent.implode(",\n".$indent, $encoded)
                : ($empty ? '' : ', ').implode(', ', $encoded);

            $json = $before.$insertion.substr($json, strlen($before));
        }

        return $json;
    }

    private function value(string $path): void
    {
        $char = $this->json[$this->i] ?? '';

        match (true) {
            $char === '{' => $this->object($path),
            $char === '[' => $this->array($path),
            $char === '"' => $this->string(),
            default => $this->scalar(),
        };
    }

    private function object(string $path): void
    {
        $this->i++;
        $this->skip();

        if (($this->json[$this->i] ?? '') === '}') {
            $this->closes[$path] = $this->i;
            $this->i++;

            return;
        }

        while (true) {
            $this->skip();
            $start = $this->i;
            $this->string();
            $key = json_decode(substr($this->json, $start, $this->i - $start));

            if (! is_string($key)) {
                throw new \RuntimeException('clave');
            }

            $this->skip();
            $this->expect(':');
            $this->skip();
            $this->value($path === '' ? $key : $path.'.'.$key);
            $this->skip();

            if (($this->json[$this->i] ?? '') === ',') {
                $this->i++;

                continue;
            }

            $this->expect('}');
            $this->closes[$path] = $this->i - 1;

            return;
        }
    }

    private function array(string $path): void
    {
        $this->i++;
        $this->skip();

        if (($this->json[$this->i] ?? '') === ']') {
            $this->i++;

            return;
        }

        for ($index = 0; ; $index++) {
            $this->skip();
            $this->value($path === '' ? (string) $index : $path.'.'.$index);
            $this->skip();

            if (($this->json[$this->i] ?? '') === ',') {
                $this->i++;

                continue;
            }

            $this->expect(']');

            return;
        }
    }

    private function string(): void
    {
        $this->expect('"');
        $length = strlen($this->json);

        while ($this->i < $length) {
            $char = $this->json[$this->i++];

            if ($char === '\\') {
                $this->i++;
            } elseif ($char === '"') {
                return;
            }
        }

        throw new \RuntimeException('cadena');
    }

    private function scalar(): void
    {
        if (preg_match('/\G(true|false|null|-?\d+(\.\d+)?([eE][+-]?\d+)?)/', $this->json, $match, 0, $this->i) !== 1) {
            throw new \RuntimeException('valor');
        }

        $this->i += strlen($match[0]);
    }

    private function expect(string $char): void
    {
        if (($this->json[$this->i] ?? '') !== $char) {
            throw new \RuntimeException($char);
        }

        $this->i++;
    }

    private function skip(): void
    {
        $length = strlen($this->json);

        while ($this->i < $length && in_array($this->json[$this->i], [' ', "\t", "\n", "\r"], true)) {
            $this->i++;
        }
    }
}
