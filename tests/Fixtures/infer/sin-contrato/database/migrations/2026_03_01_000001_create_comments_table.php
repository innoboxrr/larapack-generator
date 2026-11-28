<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->text('body');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('seen_at')->useCurrent();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
            $table->index(['site_id', 'created_at'], 'comments_site_recent_index');
        });

        Schema::create('site_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('comments');
    }
};
