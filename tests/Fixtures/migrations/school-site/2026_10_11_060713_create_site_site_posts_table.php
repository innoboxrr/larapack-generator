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
        Schema::create('site_site_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('site_id')->constrained('site_sites')->onUpdate('restrict')->onDelete('restrict');
            $table->string('kind')->default('NEWS');
            $table->string('title');
            $table->string('slug');
            $table->string('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('cover_url')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->default('DRAFT');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_site_posts');
    }
};
