<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Site's custom domain to the marketplace domain it belongs to. A single
 * registered domain (e.g. comprarcasa.com) can power MANY portals via subdomains
 * (toluca.comprarcasa.com, metepec.comprarcasa.com, …) — each is its own Site
 * pointing back to the same registered_domain. Null = an external domain the
 * tenant connected themselves (not bought through us).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('registered_domain_id')
                ->nullable()
                ->after('domain')
                ->constrained('registered_domains')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('registered_domain_id');
        });
    }
};
