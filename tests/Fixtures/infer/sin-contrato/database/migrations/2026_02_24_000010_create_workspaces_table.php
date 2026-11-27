<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 100);
            $table->string('slug');
            $table->decimal('credits', 12, 4)->default(0);
            $table->json('settings')->default(DB::raw('(JSON_OBJECT())'));
            $table->string('vertical')->default('generic')->comment('El giro del negocio');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['owner_id', 'slug']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('workspaces');
    }
};
