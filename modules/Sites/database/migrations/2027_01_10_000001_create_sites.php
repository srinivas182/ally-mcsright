<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Website engine (specs/websites/05, Sprint 17C-1): sites and pages (sections as JSON, draft and published), the
 * HQ-controlled "Powered by RightAlly" credit, and a purpose on domains so websites and the back office never share
 * a host.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('kind', 10)->default('brokerage'); // brokerage | agent
            $table->unsignedBigInteger('user_id')->nullable(); // agent sites
            $table->string('slug', 60)->nullable();
            $table->string('template', 20)->default('aurum');
            $table->json('brand'); // primary, accent, logo_url, logo_dark_url, hero_image
            $table->json('info'); // name, tagline, phone, email, address, licence, social
            $table->json('seo')->nullable(); // title_suffix, description, og_image
            $table->boolean('ai_crawlers')->default(true);
            $table->string('status', 10)->default('draft'); // draft | live
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'slug']);
        });
        $cell->create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('path', 200);
            $table->string('title', 120);
            $table->string('meta_description', 300)->nullable();
            $table->json('sections');
            $table->json('draft_sections')->nullable();
            $table->boolean('noindex')->default(false);
            $table->boolean('in_menu')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 10)->default('published'); // draft | published
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'path']);
        });

        $control = Schema::connection(Connections::control());
        $control->table('tenant_domains', function (Blueprint $table): void {
            $table->string('purpose', 10)->default('app'); // app (back office) | website
        });
        $control->create('hq_site_branding', function (Blueprint $table): void {
            $table->id();
            $table->boolean('powered_enabled')->default(true);
            $table->string('powered_text', 60)->default('Powered by RightAlly');
            $table->string('powered_url', 200)->default('https://rightally.io');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $control->create('hq_site_branding_tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->boolean('powered_enabled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->dropIfExists('hq_site_branding_tenants');
        $control->dropIfExists('hq_site_branding');
        $control->table('tenant_domains', fn (Blueprint $table) => $table->dropColumn('purpose'));
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('site_pages');
        $cell->dropIfExists('sites');
    }
};
