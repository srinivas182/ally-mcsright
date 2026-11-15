<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Social posting (specs/connect/19, C7d-1): a post written once and sent to Facebook Pages, Instagram, LinkedIn and
 * Google Business Profile, now or scheduled; one row per place so each goes out once, with its result.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ads_social_posts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('listing_ulid', 26)->nullable();
            $table->text('text');
            $table->string('link', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('status', 10)->default('scheduled'); // scheduled | posting | posted | partial | failed | cancelled
            $table->dateTime('post_at');
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'id']);
            $table->index(['status', 'post_at']);
        });
        $cell->create('ads_social_targets', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('channel', 10); // facebook | instagram | linkedin | google
            $table->string('target_id', 120);
            $table->string('name', 120);
            $table->string('status', 10)->default('waiting'); // waiting | posted | failed
            $table->string('external_id', 191)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('error', 300)->nullable();
            $table->dateTime('done_at')->nullable();
            $table->unique(['post_id', 'channel', 'target_id']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ads_social_targets');
        $cell->dropIfExists('ads_social_posts');
    }
};
