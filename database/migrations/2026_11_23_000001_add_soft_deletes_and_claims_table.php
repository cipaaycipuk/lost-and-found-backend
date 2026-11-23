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
        Schema::table('posts', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at', 'idx_posts_deleted_at');
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('claimer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('contact_whatsapp', 25)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('owner_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'status'], 'idx_claims_post_status');
            $table->index('claimer_id', 'idx_claims_claimer');
            $table->index('owner_id', 'idx_claims_owner');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('idx_posts_deleted_at');
            $table->dropSoftDeletes();
        });
    }
};
