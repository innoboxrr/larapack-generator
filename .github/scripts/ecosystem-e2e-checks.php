<?php

/*
 * Las comprobaciones de ecosystem-e2e.sh que no son una orden sino una
 * lectura: qué versión de laravel-setup se prueba, de dónde salió el LaraPack
 * instalado, si se ejecutaron todos los tests generados y qué dice verify.
 *
 * Vivían como `php -r` dentro del script y fallaban con `exit("mensaje")`, que
 * en PHP imprime el mensaje y sale con 0: ninguna podía poner el paso en rojo.
 * Aquí cada fallo pasa por fail(), que escribe en stderr y sale con 1, y
 * tests/Unit/EcosystemE2eChecksTest las alimenta con informes rotos para
 * verlas fallar.
 *
 * Uso: php ecosystem-e2e-checks.php <comprobación> argumentos...
 *
 *   pick-version <restricción>          lee `composer show --format=json`
 *                                       de stdin; imprime la última estable
 *   installed-from <installed.json> <versión>
 *   junit <junit.xml> <raíz de la app> <final de nombre de test>...
 *   verify <verify.json> <raíz de la app>
 *
 * Sin dependencias: corre con el PHP del runner, antes de que exista un
 * vendor/ al que pedirle nada.
 */

function fail(string $message): never
{
    fwrite(STDERR, rtrim($message)."\n");
    exit(1);
}

/**
 * @return array<mixed>
 */
function read_json(string $path, string $what): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;

    if ($raw === false) {
        fail("{$what}: no existe {$path}.");
    }

    $data = json_decode($raw, true);

    if (! is_array($data)) {
        fail("{$what}: {$path} no es JSON.");
    }

    return $data;
}

/**
 * La última versión estable de las que lista `composer show --all
 * --format=json <paquete> <restricción>`, que ya vienen filtradas por la
 * restricción. Si no hay ninguna, se dice cuál faltaba: es el aviso de que la
 * versión que se espera todavía no está publicada.
 */
function pick_version(string $constraint): void
{
    $data = json_decode((string) stream_get_contents(STDIN), true);
    $versions = is_array($data) && is_array($data['versions'] ?? null) ? $data['versions'] : [];
    $stable = array_values(array_filter($versions, fn ($v): bool => is_string($v) && preg_match('/^v?\d+\.\d+\.\d+$/', $v) === 1));

    if ($stable === []) {
        fail("ninguna versión estable publicada cumple {$constraint}.");
    }

    usort($stable, fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

    echo ltrim($stable[0], 'v')."\n";
}

/**
 * Que el LaraPack instalado sea el de este checkout (repositorio `path` con la
 * versión de VERSION) y no el de Packagist.
 */
function installed_from(string $installed, string $version): void
{
    $data = read_json($installed, 'installed.json');

    foreach ($data['packages'] ?? $data as $package) {
        if (is_array($package) && ($package['name'] ?? null) === 'innoboxrr/larapack-generator') {
            $found = ($package['dist']['type'] ?? '?').' '.($package['version'] ?? '?');

            if ($found !== "path {$version}") {
                fail("el LaraPack instalado no es el de este checkout ({$found}, se esperaba path {$version}): se probaría la versión publicada.");
            }

            echo "LaraPack instalado desde este checkout: {$found}\n";

            return;
        }
    }

    fail('innoboxrr/larapack-generator no está instalado.');
}

/**
 * Que se ejecutaran enteros todos los tests generados y estén los que cierran
 * la autorización. Un test que no se carga no falla, y un total que cuadra
 * puede esconder uno que falta detrás de los de la aplicación: se cuentan por
 * archivo, los escritos contra los ejecutados.
 *
 * @param  list<string>  $required
 */
function junit(string $junit, string $root, array $required): void
{
    $previous = libxml_use_internal_errors(true);
    $xml = is_file($junit) ? simplexml_load_file($junit) : false;
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        fail("PHPUnit no dejó un informe JUnit legible en {$junit}.");
    }

    $root = realpath($root) ?: fail("no existe la aplicación {$root}.");
    if (! isset($xml->testsuite[0])) {
        fail("el informe JUnit {$junit} no tiene ninguna suite.");
    }

    $suite = $xml->testsuite[0];

    if ((int) $suite['skipped'] > 0) {
        fail($suite['skipped'].' tests saltados: los generados tienen que ejecutarse todos.');
    }

    $ran = [];
    $names = [];

    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $file = realpath((string) $case['file']) ?: (string) $case['file'];
        $ran[$file] = ($ran[$file] ?? 0) + 1;
        $names[] = (string) $case['name'];
    }

    $generated = [
        ...(glob("{$root}/tests/Feature/Models/*EndpointsTest.php") ?: []),
        ...(glob("{$root}/tests/Feature/Endpoints/*/*Test.php") ?: []),
    ];

    if ($generated === []) {
        fail('no hay tests generados en tests/Feature/Models ni en tests/Feature/Endpoints.');
    }

    $short = [];

    foreach ($generated as $file) {
        $written = preg_match_all('/public function test_\w+\(\)/', (string) file_get_contents($file));
        $done = $ran[realpath($file)] ?? 0;

        if ($done !== $written) {
            $short[] = substr($file, strlen($root) + 1)." ({$done} de {$written})";
        }
    }

    if ($short !== []) {
        fail('tests generados que no se ejecutaron enteros: '.implode(', ', $short).'.');
    }

    $missing = array_values(array_filter(
        $required,
        fn (string $want): bool => array_filter($names, fn (string $name): bool => str_ends_with($name, $want)) === []
    ));

    if ($missing !== []) {
        fail('faltan tests generados: '.implode(', ', $missing).'.');
    }

    echo count($generated).' archivos generados ejecutados enteros; '.$suite['tests'].' tests en total, '.$suite['assertions']." aserciones, ninguno saltado\n";
}

/**
 * Lo que dice `larapack:verify --strict --format=json`, sin excepciones: un
 * error o un aviso es un fallo. Cada hallazgo dice de dónde viene el archivo,
 * para mandar el arreglo al repositorio que toca. Que el archivo exista con la
 * misma ruta entre los stubs de laravel-setup no basta —LaraPack regenera
 * encima algunos (el User, su política, las rutas)—: sólo si el contenido es
 * idéntico se dice que lo puso laravel-setup.
 */
function verify(string $report, string $root): void
{
    $data = read_json($report, 'verify --format=json');

    if (! is_bool($data['ok'] ?? null)) {
        fail("verify --format=json no dice si pasó (falta 'ok').");
    }

    $stubs = glob("{$root}/vendor/innoboxrr/laravel-setup/stubs/app/*", GLOB_ONLYDIR) ?: [];
    $blocking = 0;

    foreach ($data['findings'] ?? [] as $finding) {
        if (! in_array($finding['level'] ?? null, ['error', 'warning'], true)) {
            continue;
        }

        $blocking++;
        $file = (string) ($finding['file'] ?? '');

        printf(
            "  %s %s en %s: %s\n      %s\n",
            $finding['level'],
            $finding['check'] ?? '?',
            $file !== '' ? $file : '-',
            origin($root, $file, $stubs),
            $finding['message'] ?? ''
        );
    }

    if (! $data['ok'] || $blocking > 0) {
        fail("larapack:verify --strict no está limpio ({$blocking} hallazgos que bloquean).");
    }

    echo "larapack:verify --strict: sin hallazgos que bloqueen.\n";
}

/**
 * @param  list<string>  $stubs
 */
function origin(string $root, string $file, array $stubs): string
{
    if ($file === '') {
        return 'sin archivo: innoboxrr/larapack-generator o la aplicación';
    }

    $current = is_file("{$root}/{$file}") ? hash_file('sha256', "{$root}/{$file}") : null;
    $same = [];
    $differs = [];

    foreach ($stubs as $dir) {
        if (! is_file("{$dir}/{$file}")) {
            continue;
        }

        $stub = 'stubs/app/'.basename($dir)."/{$file}";

        if ($current !== null && hash_file('sha256', "{$dir}/{$file}") === $current) {
            $same[] = $stub;
        } else {
            $differs[] = $stub;
        }
    }

    if ($same !== []) {
        return 'lo puso innoboxrr/laravel-setup (idéntico a su '.$same[0].')';
    }

    if ($differs !== []) {
        return 'existe en los stubs de innoboxrr/laravel-setup ('.$differs[0].'), pero con otro contenido: lo reescribió innoboxrr/larapack-generator o la aplicación';
    }

    return 'lo generó innoboxrr/larapack-generator o es de la aplicación (no está entre los stubs de laravel-setup)';
}

$args = array_slice($argv, 1);
$check = array_shift($args);

match ($check) {
    'pick-version' => count($args) === 1 ? pick_version($args[0]) : fail('uso: pick-version <restricción>'),
    'installed-from' => count($args) === 2 ? installed_from($args[0], $args[1]) : fail('uso: installed-from <installed.json> <versión>'),
    'junit' => count($args) >= 2 ? junit($args[0], $args[1], array_slice($args, 2)) : fail('uso: junit <junit.xml> <raíz> <test>...'),
    'verify' => count($args) === 2 ? verify($args[0], $args[1]) : fail('uso: verify <verify.json> <raíz>'),
    default => fail('comprobación desconocida: '.($check ?? '(ninguna)').'. Disponibles: pick-version, installed-from, junit, verify.'),
};
