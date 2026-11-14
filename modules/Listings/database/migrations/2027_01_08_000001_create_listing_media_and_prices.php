<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Listing photos stored on our own storage/CDN in three WebP sizes, and price history (Sprint 17B-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        $listings = Schema::connection(Connections::listings());
        $listings->create('listing_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('media_key', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('source_url', 1000);
            $table->string('storage_path', 300)->nullable(); // without the size suffix
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('caption', 300)->nullable();
            $table->string('status', 8)->default('pending'); // pending | stored | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('first_attempt_at')->nullable();
            $table->string('error', 200)->nullable();
            $table->dateTime('modification_timestamp')->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'media_key']);
            $table->index(['status', 'updated_at']);
        });
        $listings->create('listing_price_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedBigInteger('old_cents')->nullable();
            $table->unsignedBigInteger('new_cents');
            $table->dateTime('changed_at');
            $table->index(['listing_id', 'changed_at']);
            $table->index(['changed_at']);
        });
        $listings->table('listings', function (Blueprint $table): void {
            $table->string('cover_path', 300)->nullable();
            $table->dateTime('price_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        $listings = Schema::connection(Connections::listings());
        $listings->table('listings', fn (Blueprint $table) => $table->dropColumn(['cover_path', 'price_changed_at']));
        $listings->dropIfExists('listing_price_changes');
        $listings->dropIfExists('listing_media');
    }
};
