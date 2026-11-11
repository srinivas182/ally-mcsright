<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Pipelines and stages, tags, smart lists (specs/crm/01, Sprint 6D).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 80);
            $table->string('kind', 20); // buyer, seller, other
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $schema->create('crm_stages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('outcome', 10)->nullable(); // won, lost
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'pipeline_id', 'sort'], 'crm_stages_pipeline_idx');
        });

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->dateTime('stage_changed_at')->nullable();

            $table->index(['tenant_id', 'stage_id'], 'crm_contacts_stage_idx');
        });

        $schema->create('crm_tags', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('name', 40);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'crm_tags_tenant_name_unq');
        });

        $schema->create('crm_contact_tag', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('crm_tags')->cascadeOnDelete();

            $table->primary(['contact_id', 'tag_id']);
            $table->index(['tenant_id', 'tag_id'], 'crm_contact_tag_tag_idx');
        });

        $schema->create('crm_smart_lists', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('name', 80);
            $table->json('filters');
            $table->boolean('shared')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'owner_user_id'], 'crm_smart_lists_owner_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('crm_smart_lists');
        $schema->dropIfExists('crm_contact_tag');
        $schema->dropIfExists('crm_tags');
        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('crm_contacts_stage_idx');
            $table->dropColumn(['pipeline_id', 'stage_id', 'stage_changed_at']);
        });
        $schema->dropIfExists('crm_stages');
        $schema->dropIfExists('crm_pipelines');
    }
};
