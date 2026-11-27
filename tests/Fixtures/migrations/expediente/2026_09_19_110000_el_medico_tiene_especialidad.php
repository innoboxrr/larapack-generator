<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La especialidad del médico deja de ser una etiqueta y pasa a ser una fila.
 *
 * QUÉ HABÍA. `practitioners.specialty` era un string con siete valores
 * posibles escritos a mano en el contrato, en el front público, en el formulario
 * de invitación y en la portada — cuatro copias de la misma lista, ya con
 * nombres distintos para la misma clave. Ningún `where` clínico lo consultaba:
 * era decorado. Y la tabla `specialties`, que existe desde el primer día, tenía
 * cero filas.
 *
 * Con la capa de plantillas encima —«la historia clínica de tu especialidad»—
 * eso deja de ser un detalle: no se puede colgar una plantilla de una etiqueta.
 *
 * QUÉ HACE ESTA MIGRACIÓN, y en este orden:
 *
 * 1. Añade `practitioners.specialty_id`, con guarda `hasColumn` porque en una
 *    base nueva ya la trae la migración de creación que reescribe el generador,
 *    y en producción no.
 *
 * 2. **Rehace dos llaves foráneas que el generador escribió en cascada.** Es
 *    el caso documentado en CLAUDE.md de «quiero la llave, con otro
 *    comportamiento», así que el contrato conserva su `constrained` y el
 *    comportamiento se corrige aquí:
 *
 *      - `practitioners.specialty_id` → **SET NULL**. Retirar una especialidad
 *        del catálogo no puede borrar a los médicos que la tenían; con cascada,
 *        un `delete` en una pantalla de administración se lleva por delante
 *        fichas, agenda y expedientes colgando de ellas.
 *      - `specialties.parent_id` → **SET NULL**. Borrar «Cardiología» borraba
 *        en silencio «Cardiología intervencionista» y, con la cascada anterior,
 *        a sus médicos. Huérfana y visible se arregla; borrada no se entera
 *        nadie.
 *
 * 3. **Rellena `specialty_id` desde el código que ya estaba guardado.** El
 *    catálogo conserva las siete claves viejas justamente para que esto sea un
 *    `join` por `code` y no una tabla de equivalencias escrita a ojo.
 *
 * El relleno es lo único que depende de que el catálogo esté sembrado. Si no lo
 * está, no falla: no empareja nada y lo dice. `EspecialidadesSeeder` es
 * idempotente y se puede correr después.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->columna();
        $this->llavesQueNoArrastran();
        $this->rellenar();
    }

    private function columna(): void
    {
        if (! Schema::hasTable('practitioners') || Schema::hasColumn('practitioners', 'specialty_id')) {
            return;
        }

        Schema::table('practitioners', function ($tabla) {
            $tabla->unsignedBigInteger('specialty_id')->nullable()->after('license_number');
        });
    }

    /**
     * Las dos llaves de especialidad, con el comportamiento correcto.
     *
     * Se tiran y se rehacen porque es la única forma de cambiar el `ON DELETE`
     * de una llave existente en MySQL.
     */
    private function llavesQueNoArrastran(): void
    {
        $this->rehacerLlave('practitioners', 'specialty_id', 'specialties', 'set null', 'fk_practitioner_especialidad');
        $this->rehacerLlave('specialties', 'parent_id', 'specialties', 'set null', 'fk_specialty_padre');
    }

    /**
     * El código que ya estaba escrito se convierte en la fila que le toca.
     *
     * No se toca a quien ya tiene `specialty_id` puesto: esta migración se
     * puede volver a correr sin deshacer una corrección hecha a mano.
     */
    private function rellenar(): void
    {
        if (! Schema::hasTable('specialties') || ! Schema::hasColumn('practitioners', 'specialty_id')) {
            return;
        }

        $catalogo = DB::table('specialties')->pluck('id', 'code');

        if ($catalogo->isEmpty()) {
            echo '  El catálogo de especialidades está vacío: no se rellenó ninguna ficha.'.PHP_EOL;
            echo '  Corre `php artisan db:seed --class=EspecialidadesSeeder` y vuelve a correr esto.'.PHP_EOL;

            return;
        }

        $puestas = 0;
        $sinCatalogo = [];

        foreach (DB::table('practitioners')->whereNull('specialty_id')->get(['id', 'specialty']) as $medico) {
            $codigo = trim((string) $medico->specialty);

            if ($codigo === '') {
                continue;
            }

            if (! isset($catalogo[$codigo])) {
                $sinCatalogo[$codigo] = ($sinCatalogo[$codigo] ?? 0) + 1;

                continue;
            }

            DB::table('practitioners')->where('id', $medico->id)->update(['specialty_id' => $catalogo[$codigo]]);
            $puestas++;
        }

        if ($puestas) {
            echo "  Especialidad puesta a {$puestas} médico(s) desde su código.".PHP_EOL;
        }

        // Se dice en voz alta y no se inventa una equivalencia: un código que
        // no está en el catálogo es una ficha que alguien tiene que mirar, y
        // adivinar aquí es peor que dejarla sin especialidad.
        foreach ($sinCatalogo as $codigo => $cuantos) {
            echo "  El código «{$codigo}» no está en el catálogo ({$cuantos} médico(s) sin especialidad).".PHP_EOL;
        }
    }

    // ─── Ayudas ──────────────────────────────────────────────────────

    private function rehacerLlave(string $tabla, string $columna, string $referida, string $alBorrar, string $nombre): void
    {
        if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
            return;
        }

        $indices = collect(DB::select("SHOW INDEX FROM `{$tabla}`"))->pluck('Key_name')->unique();

        // La llave que genera LaraPack lleva el nombre por omisión de Laravel.
        foreach (["{$tabla}_{$columna}_foreign", $nombre] as $candidata) {
            if ($indices->contains($candidata)) {
                DB::statement("ALTER TABLE `{$tabla}` DROP FOREIGN KEY `{$candidata}`");
            }
        }

        DB::statement(
            "ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` "
            ."FOREIGN KEY (`{$columna}`) REFERENCES `{$referida}` (`id`) ON DELETE {$alBorrar}"
        );
    }

    /**
     * No se deshace.
     *
     * Volver a poner una cascada que borra médicos al retirar una especialidad
     * del catálogo no es revertir una migración.
     */
    public function down(): void {}
};
