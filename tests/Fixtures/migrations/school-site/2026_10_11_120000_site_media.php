<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las fotos subidas del sitio (ronda 2 del diagrama).
 *
 * `cover_file` es el uuid de una foto del propósito `site.media`, que vive en la puerta de archivos del núcleo: una
 * noticia lleva su portada como enlace (`cover_url`) o como foto subida, nunca las dos.
 *
 * De paso se corrige `cover_url`: el diagrama y la validación dicen 500 caracteres y la columna nació de 255, de modo que
 * un enlace largo reventaba al guardar en vez de rechazarse con palabras. `change()` reemplaza la definición entera de la
 * columna, así que se vuelve a decir que admite nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_site_posts', function (Blueprint $table): void {
            $table->string('cover_url', 500)->nullable()->change();
        });

        // Si el generador volvió a emitir la tabla con la columna, no se repite.
        if (! Schema::hasColumn('site_site_posts', 'cover_file')) {
            Schema::table('site_site_posts', function (Blueprint $table): void {
                $table->char('cover_file', 36)->nullable()->after('cover_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_site_posts', 'cover_file')) {
            Schema::table('site_site_posts', function (Blueprint $table): void {
                $table->dropColumn('cover_file');
            });
        }
    }
};
