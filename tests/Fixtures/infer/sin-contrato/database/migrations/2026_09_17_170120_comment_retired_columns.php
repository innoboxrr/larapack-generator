<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columnas = [
            'retired_reason' => fn (Blueprint $t) => $t->string('retired_reason')->nullable(),
            'retired_at' => fn (Blueprint $t) => $t->timestamp('retired_at')->nullable(),
        ];

        foreach ($columnas as $nombre => $definir) {
            if (! Schema::hasColumn('comments', $nombre)) {
                Schema::table('comments', fn (Blueprint $t) => $definir($t));
            }
        }
    }
};
