<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las invitaciones al equipo, sobre la tabla de membresías que ya existe.
 *
 * POR QUÉ NO ES EL `alter` QUE GENERA LARAPACK. Al agregar las columnas al
 * contrato, LaraPack reescribe la migración de creación CON las columnas y
 * además genera un `alter`. Desde cero, el `alter` choca («Duplicate column»);
 * y si se borra, la base de producción —que ya corrió la creación— nunca recibe
 * las columnas. Ésta pregunta antes de cada columna, así que sirve igual en una
 * base nueva (no hace nada) que en producción (las agrega).
 *
 * `invited_by_id` va SIN llave foránea a propósito: el generador sólo sabe
 * escribir `onDelete('cascade')`, y con cascada borrar al usuario que invitó
 * borraría la membresía de todos los que invitó. Es un dato de bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columnas = [
            'invited_by_id' => fn (Blueprint $t) => $t->unsignedBigInteger('invited_by_id')->nullable(),
            'invited_at' => fn (Blueprint $t) => $t->timestamp('invited_at')->nullable(),
            'accepted_at' => fn (Blueprint $t) => $t->timestamp('accepted_at')->nullable(),
            'invitation_token_hash' => fn (Blueprint $t) => $t->string('invitation_token_hash')->nullable(),
            'invitation_expires_at' => fn (Blueprint $t) => $t->timestamp('invitation_expires_at')->nullable(),
            'invitation_created_user' => fn (Blueprint $t) => $t->boolean('invitation_created_user')->default(false),
        ];

        foreach ($columnas as $nombre => $definir) {
            if (! Schema::hasColumn('memberships', $nombre)) {
                Schema::table('memberships', fn (Blueprint $t) => $definir($t));
            }
        }

        // Aceptar busca por la huella del enlace: sin índice, cada clic en una
        // invitación recorre la tabla entera.
        if (! Schema::hasIndex('memberships', 'memberships_invitation_token_hash_index')) {
            Schema::table('memberships', fn (Blueprint $t) => $t->index('invitation_token_hash'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('memberships', 'memberships_invitation_token_hash_index')) {
            Schema::table('memberships', fn (Blueprint $t) => $t->dropIndex('memberships_invitation_token_hash_index'));
        }
    }
};
