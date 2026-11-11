<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Contact timeline and tasks (specs/crm/01, Sprint 5D).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_activities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('type', 20); // note, call, meeting, email, text, system
            $table->string('direction', 10)->nullable(); // in, out
            $table->string('outcome', 30)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body')->nullable();
            $table->boolean('pinned')->default(false);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id', 'occurred_at'], 'crm_activities_contact_idx');
        });

        $schema->create('crm_tasks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to');
            $table->string('type', 20)->default('follow_up');
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->dateTime('due_at');
            $table->dateTime('remind_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'assigned_to', 'completed_at', 'due_at'], 'crm_tasks_assignee_idx');
            $table->index(['tenant_id', 'contact_id', 'completed_at'], 'crm_tasks_contact_idx');
            $table->index(['remind_at', 'reminded_at'], 'crm_tasks_remind_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('crm_tasks');
        $schema->dropIfExists('crm_activities');
    }
};
