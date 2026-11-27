<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ColumnDefinition;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ForeignKey;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\MigrationReader;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\TableState;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

/**
 * La migración de un modelo: la de creación si la tabla es nueva, y una de
 * alteración con lo que cambió si ya existe.
 *
 * ────────────────────────────────────────────────────────────────────────
 * LAS REGLAS QUE NO SE NEGOCIAN (9.3.1)
 *
 *  1. Una migración de creación que ya existe NO se reescribe si cualquier
 *     otra migración toca su tabla. Quien la migró no la vuelve a crear, y una
 *     base nueva corre las dos: la columna que añadió la alteración saldría
 *     duplicada (pasaba en 50 tablas de school-management).
 *  2. El esquema actual se calcula con TODAS las migraciones del directorio,
 *     se llamen como se llamen (SchemaHistory). Lo que no se puede leer con
 *     seguridad —una columna en un bucle, la tabla en una variable, SQL
 *     crudo— no se adivina: no se escribe nada sobre ello y se dice qué
 *     archivo lo impide. Nunca un `dropColumn` ni un `add` a ciegas.
 *  3. Se compara lo que SIGNIFICA la columna, no cómo se escribió. Una llave
 *     de 7.x en cascada y un laraimport que no declara `on_delete` no piden
 *     ningún cambio: se conserva, y se sugiere declararlo.
 *  4. Lo que escribió una persona no lo deshace el generador: una columna o
 *     una llave que puso una migración que LaraPack no generó no se cambia ni
 *     se quita; se avisa.
 *  5. Una llave hacia una tabla que todavía no existe no se escribe dentro de
 *     la creación: va en una migración aparte, fechada después de las dos.
 * ────────────────────────────────────────────────────────────────────────
 */
class MigrationTool extends Tool implements ModelArtifact
{
    /**
     * Las columnas que escribe la plantilla y no el laraimport.
     */
    private const TEMPLATE_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Las tablas que esta ejecución ya creó: una llave hacia ellas ya tiene a
     * dónde apuntar.
     *
     * @var array<string, true>
     */
    private static array $created = [];

    /**
     * Las llaves que esperan a que exista la tabla a la que apuntan, por modelo.
     *
     * @var array<string, array{namespace: string, path: string, table: string, after: list<string>, keys: array<string, ForeignKey>}>
     */
    private static array $deferred = [];

    protected string $migrationPath = '';

    protected string $migrationTemplatePath = '';

    /**
     * Columnas cuya llave no va en la creación que se está escribiendo.
     *
     * @var list<string>
     */
    private array $withoutForeign = [];

    /**
     * Lo que se conserva o no se puede leer, para el informe.
     *
     * @var list<string>
     */
    private array $notices = [];

    private function setMigrationPath(): static
    {
        $this->migrationPath = get_path('database/migrations');

        return $this;
    }

    private function setMigrationTemplatePath(): static
    {
        $this->migrationTemplatePath = stubs_path('Migration');

        return $this;
    }

    public static function reset(): void
    {
        self::$created = [];
        self::$deferred = [];
        MigrationReader::reset();
    }

    public function create(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setMigrationPath()
            ->setMigrationTemplatePath();

        $this->notices = [];
        $this->withoutForeign = [];

        $stub = $this->migrationTemplatePath.'/MigrationTemplate.txt';
        $migrationFile = $this->migrationFile($this->migrationPath, 'create_'.$this->tableName().'_table');
        $history = new SchemaHistory($this->migrationPath);
        $state = $history->table($this->tableName());

        // Una migración de creación que no generó LaraPack —la de `users` que
        // trae Laravel— no se compara con el laraimport: lleva lo que este no
        // expresa (rememberToken(), unique()) y se daba por editada a mano, con
        // el consejo de escribir una alteración que quitaría esas columnas.
        if (is_file($migrationFile) && ! $this->manifest()->knows($migrationFile)) {
            Generation::record('skipped', $migrationFile, $stub, 'no la generó LaraPack: el esquema lo decide esa migración');

            return false;
        }

        // La tabla ya la crea otra migración, con otro nombre: una segunda
        // creación fallaría con «table already exists».
        if (! is_file($migrationFile) && $state->exists && $state->creator !== null) {
            Generation::record('skipped', $state->creator, $stub, "la tabla {$this->tableName()} ya la crea esta migración, que no generó LaraPack: el esquema lo decide ella");

            return false;
        }

        if (is_file($migrationFile)) {
            $others = $state->filesBesides($migrationFile);

            // Una tabla que ya existe no cambia reescribiendo su migración de
            // creación: quien ya la migró no la vuelve a crear. Lo que cambió en
            // el laraimport va en una migración de alteración.
            if (self::isFromJsonImporter()) {
                $altered = $this->alter($migrationFile, $state, $history);

                if ($altered !== null) {
                    return $altered;
                }
            }

            if ($others !== [] || $this->hasNotices()) {
                Generation::record('skipped', $migrationFile, $stub, $this->unchanged($others));

                return false;
            }
        } elseif (self::isFromJsonImporter()) {
            $this->deferForeignKeys($migrationFile, $history);
        }

        if (! is_file($migrationFile)) {
            self::$created[$this->tableName()] = true;
        }

        return $this->generate($stub, $migrationFile);
    }

    /**
     * Por qué no se escribe nada sobre una creación que ya existe.
     *
     * @param  list<string>  $others
     */
    private function unchanged(array $others): string
    {
        $reason = self::isFromJsonImporter()
            ? 'el esquema ya coincide con el laraimport'
            : 'la creación ya existe';

        if ($others !== []) {
            $names = array_map('basename', array_slice($others, 0, 3));

            $reason .= '; no se reescribe porque la tabla la tocan además '.count($others).' migraciones ('
                .implode(', ', $names).(count($others) > 3 ? ', …' : '').')';
        }

        return implode('. ', [$reason, ...$this->notices]);
    }

    /**
     * Escribe la migración con lo que añade, cambia o quita el laraimport
     * respecto de lo que ya dejaron las migraciones de la tabla.
     *
     * @return bool|null null si el esquema no cambió
     */
    private function alter(string $createFile, TableState $state, SchemaHistory $history): ?bool
    {
        $stub = $this->migrationTemplatePath.'/AlterTemplate.txt';

        if ($state->opaque !== []) {
            Generation::record('skipped', $createFile, $stub, 'no se puede leer con seguridad lo que le hacen a '
                .$this->tableName().' estas migraciones: '.implode('; ', array_unique($state->opaque))
                .'. No se escribe ninguna alteración: si el laraimport pide algo que no está, escríbela a mano');

            return false;
        }

        $plan = $this->plan($state, $history);

        if ($plan['steps'] === []) {
            return null;
        }

        // Lo que alguien escribió a mano en la migración —un índice, una
        // columna fuera del laraimport— no se puede leer con seguridad, y
        // adivinar aquí acaba en un dropColumn de algo que existe.
        if ($this->manifest()->wasCustomised($createFile)) {
            Generation::record('preserved', $createFile, $stub, 'el esquema cambió y la migración está editada a mano: escribe la alteración');

            return false;
        }

        if ($state->guessed !== []) {
            $this->notices[] = 'ojo: '.implode(', ', array_unique($state->guessed)).' cambia columnas de '.$this->tableName()
                .' con SQL armado en el momento; se dejaron fuera las columnas que nombra, pero un nombre armado a trozos no se ve: revisa la alteración';
        }

        $destination = $this->migrationPath.'/'.$this->after([...$state->files, $createFile, ...$plan['after']]).'_alter_'.$this->tableName().'_table.php';
        $reason = $this->notices === [] ? null : implode('. ', $this->notices);

        if (Generation::isDryRun()) {
            Generation::record('create', $destination, $stub, $reason);

            return true;
        }

        $up = [];
        $down = [];

        foreach ($plan['steps'] as [$stepUp, $stepDown]) {
            $up = [...$up, ...$stepUp];
            $down = [...$stepDown, ...$down];
        }

        $this->writeAlter($stub, $destination, $up, $down);

        Generation::record('create', $destination, $stub, $reason);

        return true;
    }

    /**
     * Lo que hay que hacer, en el orden en que se puede hacer: quitar llaves,
     * añadir columnas, cambiarlas, poner llaves y quitar columnas. El `down()`
     * lo deshace en el orden contrario.
     *
     * @return array{steps: list<array{0: list<string>, 1: list<string>}>, after: list<string>}
     */
    private function plan(TableState $state, SchemaHistory $history): array
    {
        $dropForeign = [];
        $add = [];
        $change = [];
        $addForeign = [];
        $drop = [];
        $after = [];

        $model = self::declaredEntry('models', $this->ModelName);
        $desired = [];

        foreach ($model['props'] as $prop) {
            $columns = MigrationReader::columns($this->getColumnDefinition($prop));

            foreach ($columns as $column) {
                $desired[$column->name] = [$column, $prop];
            }

            // Una prop que da varias columnas —morphs— se añade entera o no.
            $missing = array_filter($columns, fn (ColumnDefinition $column): bool => ! isset($state->columns[$column->name]));

            if ($missing === [] || in_array($prop['name'], self::TEMPLATE_COLUMNS, true)) {
                continue;
            }

            foreach ($columns as $column) {
                $doubt = $state->doubtAbout($column->name);

                if ($doubt !== null) {
                    $this->notices[] = "{$column->name}: la toca {$doubt} de una forma que no se puede leer; no se añade";

                    continue 2;
                }
            }

            if (count($missing) !== count($columns)) {
                $this->notices[] = "{$prop['name']}: ya existen sólo algunas de sus columnas; no se añade";

                continue;
            }

            $foreign = $columns[0]->foreign;
            $deferred = false;

            if ($foreign !== null && count($columns) === 1 && ! $this->available($foreign->table, $history)) {
                $deferred = true;
                $this->defer($columns[0]->name, $foreign);
            } elseif ($foreign !== null) {
                $after = [...$after, ...$this->creatorOf($foreign->table, $history)];
            }

            $add[] = [
                [$this->getColumnDefinition($prop, ! $deferred)],
                [match (true) {
                    in_array($prop['type'], ['morphs', 'nullableMorphs'], true) => '$table->dropMorphs('.self::phpString($prop['name']).');',
                    $foreign !== null && ! $deferred => '$table->dropConstrainedForeignId('.self::phpString($prop['name']).');',
                    default => '$table->dropColumn('.self::phpString($prop['name']).');',
                }],
            ];
        }

        foreach ($desired as $name => [$wanted, $prop]) {
            $current = $state->columns[$name] ?? null;

            if ($current === null || in_array($name, self::TEMPLATE_COLUMNS, true)) {
                continue;
            }

            $doubt = $state->doubtAbout($name);

            if ($doubt !== null) {
                // Sólo se dice si hay algo que no se hace: si la columna ya
                // coincide, la duda no cambia nada.
                if (! $current->sameColumnAs($wanted) || ($current->foreign === null) !== ($wanted->foreign === null)) {
                    $this->notices[] = "{$name}: la toca {$doubt} de una forma que no se puede leer; no se compara";
                }

                continue;
            }

            if (! $current->sameColumnAs($wanted)) {
                if ($this->generated($state->origin[$name] ?? null)) {
                    $change[] = [[$current->changedTo($wanted)->statement(true)], [$current->statement(true)]];
                } else {
                    $this->notices[] = "{$name}: la escribió a mano ".basename((string) ($state->origin[$name] ?? '')).' como «'
                        .$current->statement().'» y el laraimport dice «'.$wanted->statement().'»; no se cambia una columna escrita a mano';
                }
            }

            [$drops, $adds, $waits] = $this->foreignPlan($name, $wanted->foreign, $current->foreign, $prop, $state, $history);

            $dropForeign = [...$dropForeign, ...$drops];
            $addForeign = [...$addForeign, ...$adds];
            $after = [...$after, ...$waits];
        }

        foreach ($state->columns as $name => $current) {
            if (isset($desired[$name]) || in_array($name, self::TEMPLATE_COLUMNS, true)) {
                continue;
            }

            if (! $this->generated($state->origin[$name] ?? null)) {
                $this->notices[] = "{$name}: la añadió a mano ".basename((string) ($state->origin[$name] ?? ''))
                    .' y el laraimport no la declara; se conserva';

                continue;
            }

            $doubt = $state->doubtAbout($name);

            if ($doubt !== null) {
                $this->notices[] = "{$name}: la toca {$doubt} de una forma que no se puede leer; no se quita";

                continue;
            }

            $foreign = $current->foreign;

            $drop[] = [
                [...($foreign !== null ? [$foreign->dropStatement($name)] : []), '$table->dropColumn('.self::phpString($name).');'],
                [$current->statement(), ...($foreign !== null ? [$foreign->statement($name)] : [])],
            ];
        }

        $steps = array_values(array_filter(
            [...$dropForeign, ...$add, ...$change, ...$addForeign, ...$drop],
            fn (array $step): bool => $step[0] !== []
        ));

        return ['steps' => $steps, 'after' => $after];
    }

    /**
     * Lo que hay que hacer con la llave de una columna que ya existe.
     *
     * @param  array<string, mixed>  $prop
     * @return array{0: list<array{0: list<string>, 1: list<string>}>, 1: list<array{0: list<string>, 1: list<string>}>, 2: list<string>}
     */
    private function foreignPlan(string $name, ?ForeignKey $wanted, ?ForeignKey $current, array $prop, TableState $state, SchemaHistory $history): array
    {
        $owner = $state->foreignOrigin[$name] ?? $state->origin[$name] ?? null;

        if ($state->foreignOpaque !== []) {
            // Sólo se dice si el laraimport pide otra llave de la que se ve:
            // si coinciden, la duda no cambia nada.
            if (! self::sameForeign($wanted, $current)) {
                $this->notices[] = "{$name}: no se puede leer qué llaves dejan ".implode('; ', array_unique($state->foreignOpaque)).'; no se compara su llave';
            }

            return [[], [], []];
        }

        if ($wanted === null) {
            if ($current === null) {
                return [[], [], []];
            }

            if (! $this->generated($owner)) {
                $this->notices[] = "{$name}: la llave hacia {$current->table} la puso a mano ".basename((string) $owner).' y el laraimport no la declara; se conserva';

                return [[], [], []];
            }

            return [[[[$current->dropStatement($name)], [$current->statement($name)]]], [], []];
        }

        if ($current === null) {
            if (! $this->available($wanted->table, $history)) {
                $this->defer($name, $wanted);

                return [[], [], []];
            }

            return [[], [[[$wanted->statement($name)], [$wanted->dropStatement($name)]]], $this->creatorOf($wanted->table, $history)];
        }

        $declared = is_array($prop['declared_actions'] ?? null) ? $prop['declared_actions'] : [];
        $onDelete = in_array('on_delete', $declared, true) ? $wanted->onDelete : $current->onDelete;
        $onUpdate = in_array('on_update', $declared, true) ? $wanted->onUpdate : $current->onUpdate;
        $target = $current->sameTarget($wanted) ? $current : $wanted;
        $next = new ForeignKey($target->table, $target->column, $current->name, $onDelete, $onUpdate);

        $differs = ! $current->sameTarget($wanted)
            || ForeignKey::effective($onDelete) !== ForeignKey::effective($current->onDelete)
            || ForeignKey::effective($onUpdate) !== ForeignKey::effective($current->onUpdate);

        foreach (['on_delete' => [$wanted->onDelete, $current->onDelete], 'on_update' => [$wanted->onUpdate, $current->onUpdate]] as $key => [$asked, $has]) {
            if (! in_array($key, $declared, true) && ForeignKey::effective($asked) !== ForeignKey::effective($has)) {
                $this->notices[] = "{$name}: la llave hace ".strtoupper(str_replace('on_', 'on ', $key)).' '.ForeignKey::effective($has)
                    ." y el laraimport no declara {$key}; se conserva. Declara \"{$key}\": \"".ForeignKey::effective($has).'" para fijarla';
            }
        }

        if (! $differs) {
            return [[], [], []];
        }

        if (! $this->generated($owner)) {
            $this->notices[] = "{$name}: la llave la puso a mano ".basename((string) $owner).'; no se cambia una llave escrita a mano';

            return [[], [], []];
        }

        return [
            [[[$current->dropStatement($name)], [$current->statement($name)]]],
            [[[$next->statement($name)], [$next->dropStatement($name)]]],
            $this->creatorOf($next->table, $history),
        ];
    }

    private static function sameForeign(?ForeignKey $wanted, ?ForeignKey $current): bool
    {
        if ($wanted === null || $current === null) {
            return $wanted === $current;
        }

        return $current->sameTarget($wanted)
            && ForeignKey::effective($wanted->onDelete) === ForeignKey::effective($current->onDelete)
            && ForeignKey::effective($wanted->onUpdate) === ForeignKey::effective($current->onUpdate);
    }

    private function hasNotices(): bool
    {
        return $this->notices !== [];
    }

    /**
     * Si lo escribió LaraPack: sólo eso se cambia o se quita.
     */
    private function generated(?string $file): bool
    {
        return $file !== null && $this->manifest()->knows($file);
    }

    // LLAVES HACIA TABLAS QUE TODAVÍA NO EXISTEN

    /**
     * Si una llave hacia `$table` se puede escribir ya: la tabla es ésta, ya
     * existe, la creó esta ejecución o no es de este laraimport (`users`, la
     * de otro paquete), que es asunto de quien la crea.
     */
    private function available(string $table, SchemaHistory $history): bool
    {
        if ($table === $this->tableName() || isset(self::$created[$table]) || $history->table($table)->exists) {
            return true;
        }

        foreach (self::jsonEntries('models') as $model) {
            if (is_string($model['name'] ?? null) && TablePrefix::table(TablePrefix::tableOf($model['name'])) === $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * La migración que crea `$table`, si la hay: la que lleve su llave tiene
     * que correr después.
     *
     * @return list<string>
     */
    private function creatorOf(string $table, SchemaHistory $history): array
    {
        $creator = $history->table($table)->creator;

        return $creator === null ? [] : [$creator];
    }

    /**
     * Las llaves de una creación nueva hacia tablas de este laraimport que
     * todavía no existen —un ciclo, o una tabla que se crea después— se
     * escriben sin llave, y la llave va en una migración aparte.
     */
    private function deferForeignKeys(string $migrationFile, SchemaHistory $history): void
    {
        $model = self::declaredEntry('models', $this->ModelName);
        $moment = substr(basename($migrationFile), 0, 17);

        foreach ($model['props'] as $prop) {
            if ($prop['type'] !== 'foreignId' || ! is_string($prop['constraint'] ?? null)) {
                continue;
            }

            $table = TablePrefix::constraint($prop['constraint']);
            $creator = $history->table($table)->creator;

            // También si la tabla existe pero se crea en una migración fechada
            // después de ésta.
            $later = $creator !== null && substr(basename($creator), 0, 17) > $moment;

            if (! $later && $this->available($table, $history)) {
                continue;
            }

            $foreign = MigrationReader::columns($this->getColumnDefinition($prop))[0]->foreign ?? null;

            if ($foreign !== null) {
                $this->withoutForeign[] = $prop['name'];
                $this->defer($prop['name'], $foreign, $creator !== null ? [$creator] : []);
            }
        }
    }

    /**
     * @param  list<string>  $after
     */
    private function defer(string $column, ForeignKey $foreign, array $after = []): void
    {
        self::$deferred[$this->ModelName] ??= [
            'namespace' => $this->namespace,
            'path' => $this->migrationPath,
            'table' => $this->tableName(),
            'after' => [],
            'keys' => [],
        ];

        self::$deferred[$this->ModelName]['keys'][$column] = $foreign;
        self::$deferred[$this->ModelName]['after'] = [...self::$deferred[$this->ModelName]['after'], ...$after];
    }

    /**
     * Escribe las llaves que esperaban, una migración por tabla, cuando ya se
     * crearon todas las tablas de la importación.
     */
    public static function writeDeferredForeignKeys(): void
    {
        foreach (self::$deferred as $model => $pending) {
            (new self)->writeDeferred($model, $pending);
        }

        self::$deferred = [];
    }

    /**
     * @param  array{namespace: string, path: string, table: string, after: list<string>, keys: array<string, ForeignKey>}  $pending
     */
    private function writeDeferred(string $model, array $pending): void
    {
        $this->init($model)->setMigrationPath()->setMigrationTemplatePath();

        $stub = $this->migrationTemplatePath.'/AlterTemplate.txt';
        $history = new SchemaHistory($this->migrationPath);
        $after = [...$history->table($pending['table'])->files, ...$pending['after']];

        foreach ($pending['keys'] as $foreign) {
            $after = [...$after, ...$this->creatorOf($foreign->table, $history)];
        }

        $destination = $this->migrationPath.'/'.$this->after($after).'_add_foreign_keys_to_'.$pending['table'].'_table.php';

        if (Generation::isDryRun()) {
            Generation::record('create', $destination, $stub);

            return;
        }

        $up = [];
        $down = [];

        foreach ($pending['keys'] as $column => $foreign) {
            $up[] = $foreign->statement($column);
            $down[] = $foreign->dropStatement($column);
        }

        $this->writeAlter($stub, $destination, $up, array_reverse($down));

        Generation::record('create', $destination, $stub, 'las llaves apuntan a tablas que se crean después: van aparte, para que migrate tenga un orden posible');
    }

    // ESCRIBIR

    /**
     * @param  list<string>  $up
     * @param  list<string>  $down
     */
    private function writeAlter(string $stub, string $destination, array $up, array $down): void
    {
        if (! copy($stub, $destination)) {
            throw MakerException::copyFailed($stub, $destination);
        }

        $this->replaceData($destination);

        file_put_contents($destination, strtr(self::contents($destination), [
            '//UP//' => implode("\n            ", $up),
            '//DOWN//' => implode("\n            ", $down),
        ]));

        $this->manifest()->record($this->ModelName, $this->namespace, $destination, $stub);
    }

    /**
     * Después de la última de esas migraciones. Con el sello del reloj, una
     * alteración escrita en el mismo segundo que la creación se ordenaba antes
     * que ella —`alter` va antes que `create`— y migrate fallaba; y una tabla
     * creada en una migración fechada en el futuro corría después de la llave
     * que apunta a ella.
     *
     * @param  list<string>  $files
     */
    private function after(array $files): string
    {
        $next = MigrationTimestamp::next();

        $moments = array_filter(
            array_map(fn (string $file): string => substr(basename($file), 0, 17), $files),
            fn (string $moment): bool => \DateTimeImmutable::createFromFormat('Y_m_d_His', $moment, new \DateTimeZone('UTC')) !== false
        );

        $latest = $moments === [] ? '' : max($moments);

        if ($next > $latest) {
            return $next;
        }

        $moment = \DateTimeImmutable::createFromFormat('Y_m_d_His', $latest, new \DateTimeZone('UTC'));

        if ($moment === false) {
            throw new MakerException("«{$latest}» no es la hora de una migración.");
        }

        return $moment->modify('+1 second')->format('Y_m_d_His');
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setMigrationPath()
            ->setMigrationTemplatePath();
        $migrationFilename = MigrationTimestamp::next().'_drop_'.$this->tableName().'_table.php';
        $migrationFile = $this->migrationPath.'/'.$migrationFilename;
        // Solo proceder en caso de los archivos no existan
        if (! file_exists($migrationFile)) {
            $templateFile = $this->migrationTemplatePath.'/DropTemplate.txt';
            if (copy($templateFile, $migrationFile)) {
                // Remplace dummy data
                $this->replaceData($migrationFile);
            } else {
                throw MakerException::copyFailed($templateFile, $migrationFile);
            }
        } else {
            return false;
        }

        return true;
    }

    protected function processFileWithJson(string $migrationFile): void
    {
        $model = self::declaredEntry('models', $this->ModelName);
        $fileContent = self::contents($migrationFile);
        $columnsSchema = $this->generateMigrationColumns($model['props']);
        $updatedFileContent = str_replace('//EDIT//', $columnsSchema, $fileContent);
        file_put_contents($migrationFile, $updatedFileContent);
    }

    /**
     * @param  list<array<string, mixed>>  $props
     */
    private function generateMigrationColumns(array $props): string
    {
        $columns = '';
        foreach ($props as $index => $prop) {
            $columnDefinition = $this->getColumnDefinition($prop, ! in_array($prop['name'], $this->withoutForeign, true));
            if ($index == 0) {
                $columns .= "{$columnDefinition}\n";
            } elseif ($index == count($props) - 1) {
                $columns .= "            {$columnDefinition}";
            } else {
                $columns .= "            {$columnDefinition}\n";
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @param  bool  $withForeign  false para escribir la columna sin su llave, que va aparte
     */
    private function getColumnDefinition(array $prop, bool $withForeign = true): string
    {
        $column = "\$table->{$prop['type']}('{$prop['name']}')";

        if ($prop['nullable']) {
            $column .= '->nullable()';
        }

        if (array_key_exists('default', $prop) && $prop['default'] !== null) {

            $default = self::defaultLiteral($prop['default'], $prop['type']);

            $column .= "->default({$default})";
        }

        if ($withForeign && $prop['type'] === 'foreignId' && ! is_null($prop['constraint'])) {
            // El `constraint` del laraimport se escribe SIN prefijo, igual que
            // el nombre del modelo al que apunta. Aquí se resuelve: si la tabla
            // es de este archivo, lleva prefijo; si apunta fuera —`users`, la
            // tabla del anfitrión, la de otro paquete— se deja como está.
            $table = TablePrefix::constraint($prop['constraint']);

            $column .= '->constrained('.self::phpString($table).')'.$this->referentialActions($prop);
        }

        return $column.';';
    }
}
