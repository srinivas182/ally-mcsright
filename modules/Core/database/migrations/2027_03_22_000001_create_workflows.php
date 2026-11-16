<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * The workflow engine (specs/core/06, W1): automations with versions, runs with a step history, approvals, and the
 * trigger log that powers the dry run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 10)->default('off'); // on, off
            $table->unsignedBigInteger('version_id')->nullable(); // the published version that runs
            $table->string('template_key', 60)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('trigger', 60);
            $table->json('conditions');
            $table->json('steps');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at');
            $table->unique(['workflow_id', 'version'], 'workflow_versions_unq');
        });

        $schema->create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedBigInteger('version_id');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 200)->nullable();
            $table->string('status', 12); // running, waiting, approval, done, failed, cancelled
            $table->unsignedSmallInteger('step');
            $table->json('facts');
            $table->dateTime('resume_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->index(['status', 'resume_at'], 'workflow_runs_resume_idx');
            $table->index(['workflow_id', 'subject_type', 'subject_id'], 'workflow_runs_subject_idx');
        });

        $schema->create('workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->string('type', 40);
            $table->string('status', 10); // done, failed, skipped, waiting
            $table->string('note', 500)->nullable();
            $table->dateTime('at');
        });

        $schema->create('workflow_approvals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->string('question', 300);
            $table->unsignedTinyInteger('needed');
            $table->string('permission', 80);
            $table->unsignedBigInteger('requested_by')->nullable(); // the person whose action started the run: never an approver
            $table->string('status', 10)->default('pending'); // pending, approved, rejected
            $table->dateTime('created_at');
            $table->dateTime('decided_at')->nullable();
        });

        $schema->create('workflow_approval_votes', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('approval_id')->constrained('workflow_approvals')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('decision', 10);
            $table->string('comment', 300)->nullable();
            $table->dateTime('voted_at');
            $table->primary(['approval_id', 'user_id']);
        });

        $schema->create('workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('trigger', 60);
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->json('facts');
            $table->unsignedBigInteger('by_user_id')->nullable();
            $table->dateTime('at');
            $table->index(['tenant_id', 'trigger', 'at'], 'workflow_events_trigger_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['workflow_events', 'workflow_approval_votes', 'workflow_approvals', 'workflow_run_steps', 'workflow_runs', 'workflow_versions', 'workflows'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
