<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Sprint 17C-3a: page versions, media library, blog, redirects and menus (specs/websites/08). */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->table('sites', function (Blueprint $table): void {
            $table->json('menus')->nullable()->after('settings');
        });

        $cell->create('site_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('page_id')->constrained('site_pages')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('meta_description', 300)->nullable();
            $table->json('sections');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['page_id', 'id']);
        });

        $cell->create('site_media', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('path', 200);
            $table->string('original_name', 200)->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('alt', 250);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        $cell->create('site_posts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('title', 160);
            $table->string('excerpt', 300)->nullable();
            $table->mediumText('body');
            $table->string('category', 60)->nullable();
            $table->char('cover_media', 26)->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->string('status', 12)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->boolean('noindex')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status', 'published_at']);
        });

        $cell->create('site_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('from_path', 300);
            $table->string('to_url', 500);
            $table->unsignedSmallInteger('code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'from_path']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('site_redirects');
        $cell->dropIfExists('site_posts');
        $cell->dropIfExists('site_media');
        $cell->dropIfExists('site_page_versions');
        $cell->table('sites', function (Blueprint $table): void {
            $table->dropColumn('menus');
        });
    }
};
