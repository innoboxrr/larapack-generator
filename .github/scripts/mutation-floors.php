<?php

/*
 * El MSI de cada pieza contra su suelo, y qué mutantes nuevos escaparon.
 *
 * `--min-msi` de Infection mira las ocho piezas juntas: una podía bajar
 * mientras las otras lo compensaban. Esto lee el informe JSON de la corrida
 * (`logs.json` de infection.json5) y compara cada pieza con el suelo
 * apuntado en mutation-floors.json (la política, en docs/mutacion.md).
 *
 * El MSI es el de Infection: los mutantes detectados (muertos, por tiempo,
 * con error) entre todos, también los que ningún test recorre (la corrida va
 * con --with-uncovered). Falla si una pieza baja de su suelo, si una pieza con
 * suelo no tiene mutantes —se renombró o salió de infection.json5 sin
 * quitarla de aquí— o si se muta un archivo sin suelo.
 *
 * Un PR rojo tiene que decir qué hacer en un minuto. Por eso, con el catálogo
 * de los supervivientes conocidos (mutation-survivors.json: los que quedaron
 * vivos en la última medición, equivalentes o apuntados en docs/mutacion.md),
 * lista cada mutante que escapa y no está en él, con su archivo:línea y su
 * diff, y dice cuántos mutantes detectados más le faltan a la pieza. Un
 * mutante del catálogo se reconoce por su pieza, su mutador y las líneas que
 * cambia, no por el número de línea: añadir código encima no lo vuelve nuevo.
 *
 * Uso:
 *   php .github/scripts/mutation-floors.php <informe> <suelos> [<catálogo>]
 *   php .github/scripts/mutation-floors.php --write-catalog <informe> <catálogo>
 */

/**
 * @return array<mixed>
 */
function readJson(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    $data = $raw === false ? null : json_decode($raw, true);

    if (! is_array($data)) {
        fwrite(STDERR, "{$path}: no existe o no es JSON.\n");
        exit(2);
    }

    return $data;
}

/**
 * Las líneas que el mutante quita y pone, sin la sangría ni los espacios de
 * más: lo que lo identifica aunque el archivo se mueva.
 *
 * @return array{0: string, 1: string}
 */
function changedLines(string $diff): array
{
    $removed = [];
    $added = [];

    foreach (preg_split('/\R/', $diff) ?: [] as $line) {
        if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
            continue;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', substr($line, 1)));

        if (str_starts_with($line, '-')) {
            $removed[] = $text;
        } elseif (str_starts_with($line, '+')) {
            $added[] = $text;
        }
    }

    return [implode("\n", $removed), implode("\n", $added)];
}

/**
 * Ruta relativa al directorio de trabajo, con barras normales.
 */
function displayPath(string $file): string
{
    $file = str_replace('\\', '/', $file);
    $cwd = str_replace('\\', '/', (string) getcwd()).'/';

    return str_starts_with($file, $cwd) ? substr($file, strlen($cwd)) : $file;
}

/**
 * Cada mutante del informe, con su pieza y si se detectó.
 *
 * @param  array<mixed>  $report
 * @return list<array{piece: string, detected: bool, kind: string, mutator: string, file: string, line: int, removed: string, added: string}>
 */
function mutants(array $report): array
{
    // Lo que Infection cuenta como detectado; los escapados y los que ningún
    // test recorre cuentan en contra. Los ignorados no cuentan.
    $kinds = [
        'killed' => true, 'killedByStaticAnalysis' => true, 'timeouted' => true, 'errored' => true,
        'syntaxErrors' => true, 'escaped' => false, 'uncovered' => false,
    ];
    $mutants = [];

    foreach ($kinds as $kind => $detected) {
        foreach ($report[$kind] ?? [] as $mutant) {
            $file = displayPath((string) ($mutant['mutator']['originalFilePath'] ?? ''));
            [$removed, $added] = changedLines((string) ($mutant['diff'] ?? ''));

            $mutants[] = [
                'piece' => basename($file, '.php'),
                'detected' => $detected,
                'kind' => $kind,
                'mutator' => (string) ($mutant['mutator']['mutatorName'] ?? ''),
                'file' => $file,
                'line' => (int) ($mutant['mutator']['originalStartLine'] ?? 0),
                'removed' => $removed,
                'added' => $added,
            ];
        }
    }

    return $mutants;
}

/**
 * @param  array{mutator: string, removed: string, added: string}  $mutant
 */
function mutantKey(array $mutant): string
{
    return $mutant['mutator']."\0".$mutant['removed']."\0".$mutant['added'];
}

/**
 * Como Infection: en tanto por ciento, redondeado a dos decimales.
 */
function msi(int $detected, int $total): float
{
    return round($detected / $total * 100, 2, PHP_ROUND_HALF_UP);
}

if (($argv[1] ?? null) === '--write-catalog') {
    if ($argc !== 4) {
        fwrite(STDERR, "Uso: php {$argv[0]} --write-catalog <informe de Infection> <catálogo>\n");
        exit(2);
    }

    $catalog = [];

    foreach (mutants(readJson($argv[2])) as $mutant) {
        if (! $mutant['detected']) {
            $catalog[$mutant['piece']][] = [
                'line' => $mutant['line'],
                'mutator' => $mutant['mutator'],
                'kind' => $mutant['kind'],
                'removed' => $mutant['removed'],
                'added' => $mutant['added'],
            ];
        }
    }

    ksort($catalog);

    foreach ($catalog as &$entries) {
        usort($entries, fn (array $a, array $b): int => [$a['line'], $a['mutator'], $a['added']] <=> [$b['line'], $b['mutator'], $b['added']]);
    }

    unset($entries);
    file_put_contents($argv[3], json_encode((object) $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    printf("%d supervivientes de %d piezas en %s.\n", array_sum(array_map('count', $catalog)), count($catalog), $argv[3]);
    exit(0);
}

if ($argc !== 3 && $argc !== 4) {
    fwrite(STDERR, "Uso: php {$argv[0]} <informe de Infection> <suelos> [<catálogo>]\n");
    exit(2);
}

$all = mutants(readJson($argv[1]));
$floors = readJson($argv[2]);
$catalogPath = $argv[3] ?? null;

// Cuántas veces está cada superviviente conocido, por pieza: dos mutantes
// iguales en dos sitios del mismo archivo son dos entradas.
$known = [];

foreach ($catalogPath === null ? [] : readJson($catalogPath) as $piece => $entries) {
    foreach (is_array($entries) ? $entries : [] as $entry) {
        $key = mutantKey(['mutator' => (string) ($entry['mutator'] ?? ''), 'removed' => (string) ($entry['removed'] ?? ''), 'added' => (string) ($entry['added'] ?? '')]);
        $known[$piece][$key] = ($known[$piece][$key] ?? 0) + 1;
    }
}

$pieces = [];
$new = [];

foreach ($all as $mutant) {
    $piece = $mutant['piece'];
    $pieces[$piece] ??= ['total' => 0, 'detected' => 0];
    $pieces[$piece]['total']++;

    if ($mutant['detected']) {
        $pieces[$piece]['detected']++;

        continue;
    }

    $key = mutantKey($mutant);

    if (($known[$piece][$key] ?? 0) > 0) {
        $known[$piece][$key]--;
    } else {
        $new[$piece][] = $mutant;
    }
}

$failures = [];
$notes = [];
ksort($pieces);
ksort($floors);

printf("%-18s %9s %8s %7s %8s\n", 'Pieza', 'Mutantes', 'MSI', 'Suelo', 'Holgura');

foreach ($floors as $piece => $floor) {
    if (! isset($pieces[$piece])) {
        $failures[] = "{$piece}: tiene suelo y ningún mutante. Si salió de infection.json5 o cambió de nombre, quítala o renómbrala en {$argv[2]}.";

        continue;
    }

    ['total' => $total, 'detected' => $detected] = $pieces[$piece];
    $msi = msi($detected, $total);

    // La holgura, en mutantes: cuántos más pueden escapar sin bajar del
    // suelo; si es negativa, cuántos detectados más le faltan.
    $slack = 0;

    while ($msi >= $floor && $slack < $detected && msi($detected - $slack - 1, $total) >= $floor) {
        $slack++;
    }

    while ($msi < $floor && msi($detected - $slack, $total) < $floor && $detected - $slack < $total) {
        $slack--;
    }

    printf("%-18s %9d %7.2f%% %6s%% %8d\n", $piece, $total, $msi, $floor, $slack);

    $list = '';

    if ($catalogPath !== null && isset($new[$piece])) {
        foreach ($new[$piece] as $mutant) {
            $list .= sprintf(
                "    %s:%d [%s, %s]\n%s%s",
                $mutant['file'],
                $mutant['line'],
                $mutant['mutator'],
                $mutant['kind'] === 'uncovered' ? 'sin recorrer' : 'escapa',
                preg_replace('/^/m', '      - ', $mutant['removed'])."\n",
                preg_replace('/^/m', '      + ', $mutant['added'])."\n",
            );
        }
    }

    if ($msi < $floor) {
        $failures[] = sprintf(
            '%s: MSI %.2f %% por debajo de su suelo, %s %%: %.2f puntos, %d %s más.',
            $piece,
            $msi,
            $floor,
            $floor - $msi,
            -$slack,
            -$slack === 1 ? 'mutante detectado' : 'mutantes detectados',
        ).($list === '' ? '' : "\n  Escapan y no están en {$catalogPath}:\n".rtrim($list, "\n"));
    } elseif ($list !== '') {
        $notes[] = "{$piece}: no baja de su suelo, pero escapan y no están en {$catalogPath}:\n".rtrim($list, "\n");
    }
}

foreach (array_diff_key($pieces, $floors) as $piece => $counts) {
    $failures[] = "{$piece}: se muta ({$counts['total']} mutantes) y no tiene suelo en {$argv[2]}.";
}

// Los del catálogo que ya no escapan: un test nuevo los mató, o el código
// cambió. No es un fallo, pero el catálogo se queda viejo.
$gone = array_sum(array_map('array_sum', $known));

if ($gone > 0) {
    $notes[] = "{$gone} del catálogo ya no escapan: un test los mata o su código cambió. Regenéralo con --write-catalog.";
}

if ($notes !== []) {
    echo "\n".implode("\n", $notes)."\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\n".implode("\n", $failures)."\n");
    exit(1);
}

echo "\nNinguna pieza baja de su suelo.\n";
