<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA3 (specs/grow/13): Reels and Shorts. A Reel is made from a listing design's story frames (the cover, the
 * photos, the details) with slow camera moves, crossfades and a track from RightAlly's licensed music library (HQ
 * keeps the licences). Social posts can carry the video (Instagram Reels, Facebook Page videos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('studio_music', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title', 120);
            $table->string('artist', 120);
            $table->string('mood', 20); // luxury | calm | upbeat | cinematic
            $table->string('licence', 300); // the licence RightAlly holds, in words
            $table->string('licence_url', 300)->nullable();
            $table->string('path', 255); // private disk
            $table->unsignedSmallInteger('seconds')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['active', 'mood']);
        });

        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_videos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('design_id');
            $table->char('listing_ulid', 26)->nullable();
            $table->char('music_ulid', 26)->nullable();
            $table->string('status', 12)->default('rendering'); // rendering | ready | failed
            $table->string('path', 255)->nullable();
            $table->string('poster_path', 255)->nullable();
            $table->unsignedSmallInteger('seconds')->default(0);
            $table->string('error', 300)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        $cell->table('ads_social_posts', function (Blueprint $table): void {
            $table->string('video_url', 500)->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_social_posts', function (Blueprint $table): void {
            $table->dropColumn('video_url');
        });
        Schema::connection(Connections::cell())->dropIfExists('studio_videos');
        Schema::connection(Connections::control())->dropIfExists('studio_music');
    }
};
