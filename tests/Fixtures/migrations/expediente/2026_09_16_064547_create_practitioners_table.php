<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('practitioners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onUpdate('cascade')->onDelete('cascade');
            $table->string('license_number');
            // La CURP de quien atiende (curpPrestador de las guías de la DGIS) y su
            // formación con la clave de CAT_FORMACION y la versión del catálogo.
            $table->string('curp')->nullable();
            $table->string('education_code')->nullable();
            $table->unsignedBigInteger('education_catalog_release_id')->nullable();
            // La identidad del prestador como la piden las guías del SIS (GIIS-B015
            // 5.5, variables 2 y 4–7): el nombre partido como en la CURP, el país
            // de nacimiento (catálogo PAIS) y el tipo de personal (TIPO PERSONAL–SIS).
            $table->string('given_name')->nullable();
            $table->string('paternal_name')->nullable();
            $table->string('maternal_name')->nullable();
            $table->string('birth_country_code')->nullable();
            $table->unsignedSmallInteger('sis_staff_type')->nullable();
            // Sin llave AQUÍ, y no por capricho: esta migración corre antes que
            // `create_specialties_table`, así que una base nueva moría con
            // «Failed to open the referenced table 'specialties'». La llave la
            // escribe `2026_09_19_110000_el_medico_tiene_especialidad`, que
            // corre después de las dos y además la pone con `SET NULL`.
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->string('specialty')->default('orthopedics');
            $table->string('subspecialty')->nullable();
            $table->string('title')->nullable()->default('Dr.');
            $table->string('public_slug');
            $table->text('bio')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->longText('payload')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practitioners');
    }
};
