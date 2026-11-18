<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * C03 (specs/websites/02): each stored photo gets a room, feature tags and plain-words alt text from the photo
 * tagging service; a listing's features (pool, fireplace, …) are kept one per row so search can filter on them.
 * Nearby schools and transit are looked up once per listing and kept with their straight-line distances.
 */
return new class extends Migration
{
    public function up(): void
    {
        $listings = Schema::connection(Connections::listings());
        $listings->table('listing_media', function (Blueprint $table): void {
            $table->string('room', 40)->nullable();
            $table->text('tags')->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->timestamp('tagged_at')->nullable();
        });
        $listings->table('listings', function (Blueprint $table): void {
            $table->timestamp('photos_tagged_at')->nullable();
            $table->timestamp('places_at')->nullable();
        });
        $listings->create('listing_features', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->string('feature', 40);
            $table->unique(['listing_id', 'feature'], 'listing_features_unique');
            $table->index(['feature', 'listing_id'], 'listing_features_feature_idx');
        });
        $listings->create('listing_places', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->string('kind', 20);
            $table->string('subtype', 30)->nullable();
            $table->string('name', 160);
            $table->unsignedInteger('meters');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('provider', 20);
            $table->index(['listing_id', 'kind'], 'listing_places_listing_idx');
        });
    }

    public function down(): void
    {
        $listings = Schema::connection(Connections::listings());
        $listings->dropIfExists('listing_places');
        $listings->dropIfExists('listing_features');
        $listings->table('listings', function (Blueprint $table): void {
            $table->dropColumn(['photos_tagged_at', 'places_at']);
        });
        $listings->table('listing_media', function (Blueprint $table): void {
            $table->dropColumn(['room', 'tags', 'alt_text', 'tagged_at']);
        });
    }
};
