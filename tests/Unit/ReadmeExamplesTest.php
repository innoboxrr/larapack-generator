<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use PHPUnit\Framework\TestCase;

/**
 * El README es la segunda fuente de verdad del contrato, y una segunda fuente
 * de verdad siempre acaba divergiendo: el ejemplo que habia hasta ahora
 * declaraba las catorce claves de cada propiedad y su UpdateRequest no
 * validaba el identificador, con lo que copiarlo daba un archivo que hoy no
 * pasa la validacion.
 *
 * Que el propio esquema valide los ejemplos del README cierra esa puerta.
 */
final class ReadmeExamplesTest extends TestCase
{
    public function test_los_ejemplos_del_readme_son_laraimports_validos(): void
    {
        $examples = $this->examples();

        $this->assertNotEmpty($examples, 'No se encontró ningún ejemplo de laraimport en el README.');

        foreach ($examples as $index => $example) {
            $decoded = json_decode($example, true);

            $this->assertIsArray($decoded, "El ejemplo #{$index} del README no es JSON válido.");

            ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray($decoded);

            $this->assertNotNull($document, "El ejemplo #{$index} del README no valida:\n" . $this->format($errors));
            $this->assertSame([], $errors, "El ejemplo #{$index} del README deja avisos:\n" . $this->format($errors));
        }
    }

    /**
     * Los bloques ```json que van bajo el titulo del ejemplo. Los anteriores
     * son salidas de comandos, no laraimports.
     *
     * @return array<int, string>
     */
    private function examples(): array
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $start = strpos($readme, '## Ejemplo de laraimport.json');

        $this->assertNotFalse($start, 'El README ya no tiene la sección de ejemplos.');

        $section = substr($readme, $start);
        $end = strpos($section, "\n## ", 1);

        if ($end !== false) {
            $section = substr($section, 0, $end);
        }

        preg_match_all('/```json\R(.*?)```/s', $section, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function format(array $errors): string
    {
        return implode("\n", array_map(
            fn (array $e): string => "  [{$e['level']}] {$e['path']} {$e['message']}",
            $errors
        ));
    }
}
