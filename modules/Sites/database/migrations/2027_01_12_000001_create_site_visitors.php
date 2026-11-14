<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 17C-2b: website visitors, their journey events, favourites, sign-in codes, and site settings
 * (specs/websites/07).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->table('sites', function (Blueprint $table): void {
            $table->json('settings')->nullable()->after('ai_crawlers');
        });

        $cell->create('site_visitors', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('vid', 40);
            $table->foreignId('merged_into')->nullable()->constrained('site_visitors')->nullOnDelete();
            $table->string('email', 254)->nullable();
            $table->string('name', 160)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('first_referrer', 500)->nullable();
            $table->string('landing_url', 500)->nullable();
            $table->unsignedInteger('visits')->default(1);
            $table->unsignedInteger('events_today')->default(0);
            $table->date('events_day')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('summary_ai')->default(false);
            $table->timestamp('summary_at')->nullable();
            $table->json('alerts')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'vid']);
            $table->index(['tenant_id', 'email']);
        });

        $cell->create('site_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('visitor_id')->constrained('site_visitors')->cascadeOnDelete();
            $table->string('type', 20);
            $table->char('listing_ulid', 26)->nullable();
            $table->string('path', 300)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['visitor_id', 'created_at']);
            $table->index(['visitor_id', 'listing_ulid']);
        });

        $cell->create('site_favourites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('visitor_id')->constrained('site_visitors')->cascadeOnDelete();
            $table->char('listing_ulid', 26);
            $table->timestamp('created_at')->nullable();
            $table->unique(['visitor_id', 'listing_ulid']);
        });

        $cell->create('site_login_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('email', 254);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('site_login_codes');
        $cell->dropIfExists('site_favourites');
        $cell->dropIfExists('site_events');
        $cell->dropIfExists('site_visitors');
        $cell->table('sites', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
