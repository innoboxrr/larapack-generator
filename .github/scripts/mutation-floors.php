<?php

/*
 * El MSI de cada pieza contra su suelo.
 *
 * `--min-msi` de Infection mira las ocho piezas juntas: una podía bajar
 * mientras las otras lo compensaban, y LegacyFix —la más baja— era la que
 * menos margen tenía. Esto lee el informe JSON de la corrida
 * (`logs.json` de infection.json5) y compara cada pieza con el suelo
 * apuntado en mutation-floors.json: lo logrado, redondeado hacia abajo.
 *
 * El MSI es el de Infection: los mutantes detectados (muertos, por tiempo,
 * con error) entre todos, también los que ningún test recorre (la corrida va
 * con --with-uncovered). Falla si una pieza baja de su suelo, si una pieza con
 * suelo no tiene mutantes —se renombró o salió de infection.json5 sin
 * quitarla de aquí— o si se muta un archivo sin suelo.
 *
 * Uso: php .github/scripts/mutation-floors.php .infection/infection.json .github/mutation-floors.json
 */

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php {$argv[0]} <informe de Infection> <suelos>\n");
    exit(2);
}

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

$report = readJson($argv[1]);
$floors = readJson($argv[2]);

// Lo que Infection cuenta como detectado; los escapados y los que ningún
// test recorre cuentan en contra. Los ignorados no cuentan.
$detected = ['killed', 'killedByStaticAnalysis', 'timeouted', 'errored', 'syntaxErrors'];
$undetected = ['escaped', 'uncovered'];
$pieces = [];

foreach ([...$detected, ...$undetected] as $kind) {
    foreach ($report[$kind] ?? [] as $mutant) {
        $file = str_replace('\\', '/', (string) ($mutant['mutator']['originalFilePath'] ?? ''));
        $piece = basename($file, '.php');
        $pieces[$piece] ??= ['total' => 0, 'detected' => 0];
        $pieces[$piece]['total']++;

        if (in_array($kind, $detected, true)) {
            $pieces[$piece]['detected']++;
        }
    }
}

$failures = [];
ksort($pieces);
ksort($floors);

printf("%-18s %9s %8s %7s\n", 'Pieza', 'Mutantes', 'MSI', 'Suelo');

foreach ($floors as $piece => $floor) {
    if (! isset($pieces[$piece])) {
        $failures[] = "{$piece}: tiene suelo y ningún mutante. Si salió de infection.json5 o cambió de nombre, quítala o renómbrala en {$argv[2]}.";

        continue;
    }

    // Como Infection: en tanto por ciento, redondeado a dos decimales.
    $msi = round($pieces[$piece]['detected'] / $pieces[$piece]['total'] * 100, 2, PHP_ROUND_HALF_UP);
    printf("%-18s %9d %7.2f%% %6s%%\n", $piece, $pieces[$piece]['total'], $msi, $floor);

    if ($msi < $floor) {
        $failures[] = sprintf('%s: MSI %.2f %% por debajo de su suelo, %s %%.', $piece, $msi, $floor);
    }
}

foreach (array_diff_key($pieces, $floors) as $piece => $counts) {
    $failures[] = "{$piece}: se muta ({$counts['total']} mutantes) y no tiene suelo en {$argv[2]}.";
}

if ($failures !== []) {
    fwrite(STDERR, "\n".implode("\n", $failures)."\n");
    exit(1);
}

echo "\nNinguna pieza baja de su suelo.\n";
