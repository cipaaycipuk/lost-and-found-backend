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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->string('title', 150);
            $table->string('category', 80);
            $table->string('location', 120);
            $table->date('incident_date');
            $table->text('description');
            $table->timestamps();

            $table->index(['type', 'status'], 'idx_posts_type_status');
            $table->index('category', 'idx_posts_category');
            $table->index('location', 'idx_posts_location');
            $table->index('created_at', 'idx_posts_created_at');
        });

        Schema::create('post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->text('image_url');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_images');
        Schema::dropIfExists('posts');
    }
};
