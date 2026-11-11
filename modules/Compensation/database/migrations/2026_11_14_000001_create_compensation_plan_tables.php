<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Commission and onboarding plans, immutable published versions and agent assignments
 * (specs/compensation/01).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('comp_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('type', 20);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status'], 'comp_plans_tenant_type_status_idx');
        });

        $schema->create('comp_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('plan_id')->constrained('comp_plans');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->json('rules');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('change_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version'], 'comp_plan_versions_plan_version_unq');
            $table->index(['tenant_id', 'plan_id', 'status', 'effective_from'], 'comp_plan_versions_resolve_idx');
        });

        $schema->create('comp_plan_assignments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->foreignId('plan_id')->constrained('comp_plans');
            $table->string('plan_type', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'agent_user_id', 'plan_type', 'effective_from'], 'comp_plan_assignments_resolve_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('comp_plan_assignments');
        $schema->dropIfExists('comp_plan_versions');
        $schema->dropIfExists('comp_plans');
    }
};
