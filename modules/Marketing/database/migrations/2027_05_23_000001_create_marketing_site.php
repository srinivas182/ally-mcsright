<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SG1 (specs/marketing/01): RightAlly's own website, in the control database (it belongs to HQ, not a brokerage).
 * Pages keep a private draft beside the live copy, with the last 20 published versions; blog posts are Markdown.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('marketing_pages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('locale', 2)->default('en');
            $table->string('slug', 80)->default(''); // '' = home
            $table->string('title', 70);
            $table->string('description', 170);
            $table->json('blocks')->nullable(); // live
            $table->string('draft_title', 70)->nullable();
            $table->string('draft_description', 170)->nullable();
            $table->json('draft_blocks')->nullable();
            $table->string('status', 5)->default('draft'); // draft, live
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['locale', 'slug']);
        });
        $control->create('marketing_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('page_id')->index();
            $table->string('title', 70);
            $table->string('description', 170);
            $table->json('blocks');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('created_at');
        });
        $control->create('marketing_posts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('locale', 2)->default('en');
            $table->string('slug', 100);
            $table->string('title', 110);
            $table->string('description', 170);
            $table->mediumText('body');
            $table->string('author', 120);
            $table->string('status', 5)->default('draft'); // draft, live
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        foreach (['marketing_posts', 'marketing_page_versions', 'marketing_pages'] as $t) {
            $control->dropIfExists($t);
        }
    }
};
