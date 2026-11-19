<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * MK3 (and MK4), control database: the AI content desk — one desk per state for HQ (tenant 0) and one per brokerage
 * or agent site; topic bookings (no two sites in a city get the same topic or angle within six months); drafts waiting
 * for review; and indexing numbers from Search Console that decide the posting level.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $c = Schema::connection(Connections::control());
        $c->create('content_desks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(0); // 0 = HQ
            $t->unsignedBigInteger('user_id')->nullable(); // MK4: an agent's own site
            $t->string('state', 2);
            $t->string('mode', 4)->default('off'); // off, test, live
            $t->unsignedTinyInteger('level')->default(1);
            $t->dateTime('level_since');
            $t->string('paused_reason', 200)->nullable();
            $t->json('cities')->nullable(); // HQ's own city list; empty = by number of listings
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'state'], 'content_desks_scope_uq');
        });
        $c->create('content_bookings', function (Blueprint $t): void {
            $t->id();
            $t->string('state', 2);
            $t->string('city', 80);
            $t->string('topic', 60);
            $t->string('angle', 60);
            $t->unsignedBigInteger('desk_id');
            $t->dateTime('until');
            $t->timestamps();
            $t->index(['state', 'city', 'topic', 'until'], 'content_bookings_lookup_idx');
        });
        $c->create('content_drafts', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->unsignedBigInteger('desk_id');
            $t->unsignedBigInteger('tenant_id')->default(0);
            $t->string('state', 2);
            $t->string('city', 80);
            $t->string('kind', 10); // landing, article, post
            $t->string('topic', 60);
            $t->string('title', 120);
            $t->string('slug', 100);
            $t->string('description', 300);
            $t->longText('body');
            $t->json('faq')->nullable();
            $t->json('facts')->nullable();
            $t->json('checks')->nullable();
            $t->decimal('similarity', 4, 3)->default(0);
            $t->string('status', 10)->default('review'); // review, published, rejected, skipped
            $t->string('note', 300)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('published_url', 300)->nullable();
            $t->timestamps();
            $t->index(['desk_id', 'status', 'created_at'], 'content_drafts_desk_idx');
            $t->index(['state', 'status'], 'content_drafts_state_idx');
        });
        $c->create('content_index_stats', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('desk_id');
            $t->date('day');
            $t->unsignedInteger('submitted')->default(0);
            $t->unsignedInteger('indexed')->default(0);
            $t->unsignedInteger('warnings')->default(0);
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->string('source', 20);
            $t->timestamps();
            $t->unique(['desk_id', 'day'], 'content_index_stats_day_uq');
        });
    }

    public function down(): void
    {
        $c = Schema::connection(Connections::control());
        foreach (['content_index_stats', 'content_drafts', 'content_bookings', 'content_desks'] as $table) {
            $c->dropIfExists($table);
        }
    }
};
