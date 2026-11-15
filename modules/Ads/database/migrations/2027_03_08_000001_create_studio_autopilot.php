<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Ally Studio Autopilot (specs/grow/13, SA2): each person's Autopilot settings, the posts Studio plans for them
 * (one per moment, never twice) and what Studio last saw of each listing, so changes become moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_autopilot', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('mode', 8)->default('approve'); // off | approve | auto
            $table->json('places')->nullable();   // social place keys; null: every place
            $table->json('times')->nullable();    // preferred local times, "HH:MM"
            $table->unsignedTinyInteger('per_week')->default(5);
            $table->json('moments')->nullable();  // moment kinds switched on; null: all
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        $cell->create('studio_queue', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('source_key', 120); // e.g. listing:{ulid}:just_listed, market:2026-W23
            $table->string('moment', 20);
            $table->char('listing_ulid', 26)->nullable();
            $table->string('batch', 26)->nullable();
            $table->unsignedBigInteger('design_id')->nullable();
            $table->text('caption')->nullable();
            $table->text('caption_es')->nullable();
            $table->json('hashtags')->nullable();
            $table->dateTime('post_at')->nullable();
            $table->string('status', 10)->default('designing'); // designing | awaiting | scheduled | skipped | failed
            $table->unsignedBigInteger('social_post_id')->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'source_key']);
            $table->index(['status', 'post_at']);
        });
        $cell->create('studio_listing_seen', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('listing_ulid', 26);
            $table->string('status', 30)->nullable();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'listing_ulid']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('studio_listing_seen');
        $cell->dropIfExists('studio_queue');
        $cell->dropIfExists('studio_autopilot');
    }
};
