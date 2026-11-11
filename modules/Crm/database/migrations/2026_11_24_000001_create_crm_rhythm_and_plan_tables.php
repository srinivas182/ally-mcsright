<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Relationship rhythm and follow-up plans (CRM additions, Sprint 9D).
 * birthday_md / closed_md hold month*100+day so "this week" works the same on every database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->char('rhythm', 1)->nullable();
            $table->date('closed_on')->nullable();
            $table->unsignedSmallInteger('birthday_md')->nullable();
            $table->unsignedSmallInteger('closed_md')->nullable();

            $table->index(['tenant_id', 'rhythm'], 'crm_contacts_rhythm_idx');
            $table->index(['tenant_id', 'birthday_md'], 'crm_contacts_bday_idx');
            $table->index(['tenant_id', 'closed_md'], 'crm_contacts_closed_idx');
        });

        $schema->create('crm_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->string('trigger', 10)->default('manual'); // manual, source, stage
            $table->string('trigger_value', 40)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('shared')->default(false);
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->timestamps();
        });

        $schema->create('crm_plan_steps', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('plan_id')->constrained('crm_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->unsignedSmallInteger('day');
            $table->string('type', 10); // call, text, email, task
            $table->string('title', 200);
            $table->text('message')->nullable();
        });

        $schema->create('crm_plan_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('plan_id')->constrained('crm_plans')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to');
            $table->string('status', 10)->default('active'); // active, stopped, completed
            $table->string('stop_reason', 100)->nullable();
            $table->unsignedSmallInteger('next_step')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('next_due_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'next_due_at'], 'crm_enrollments_due_idx');
            $table->index(['tenant_id', 'contact_id', 'status'], 'crm_enrollments_contact_idx');
        });

        $schema->table('crm_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_enrollment_id')->nullable();
            $table->text('message')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('crm_tasks', fn (Blueprint $table) => $table->dropColumn(['plan_enrollment_id', 'message']));
        $schema->dropIfExists('crm_plan_enrollments');
        $schema->dropIfExists('crm_plan_steps');
        $schema->dropIfExists('crm_plans');
        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('crm_contacts_rhythm_idx');
            $table->dropIndex('crm_contacts_bday_idx');
            $table->dropIndex('crm_contacts_closed_idx');
            $table->dropColumn(['rhythm', 'closed_on', 'birthday_md', 'closed_md']);
        });
    }
};
