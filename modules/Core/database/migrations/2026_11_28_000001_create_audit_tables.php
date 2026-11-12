<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Tamper-evident audit log (specs/core/04, Sprint 7A).
 *
 * audit_outbox is written in the same transaction as the change, so no event is lost; a worker seals
 * events into audit_events in order per brokerage, with a gapless sequence and a SHA-256 hash chain
 * (audit_heads holds each chain's tip). In production the audit database user may only INSERT and
 * SELECT on audit_events (no UPDATE or DELETE).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('audit_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->json('payload');
            $table->dateTime('created_at', 6);
        });

        $schema->create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('chain_seq');
            $table->dateTime('occurred_at', 6);
            $table->string('actor_type', 20); // user, system, api_client, hq_support, ai
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 160)->nullable();
            $table->string('actor_kind', 20)->nullable();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('impersonator_name', 160)->nullable();
            $table->unsignedBigInteger('on_behalf_of_user_id')->nullable();
            $table->string('automation', 40)->nullable();
            $table->string('automation_scope', 160)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('session_ref', 64)->nullable(); // SHA-256 of the session ID, never the ID
            $table->string('request_id', 64)->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('subject_label', 200)->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('severity', 10)->default('info');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['tenant_id', 'chain_seq'], 'audit_events_chain_unq');
            $table->index(['tenant_id', 'occurred_at'], 'audit_events_time_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'audit_events_subject_idx');
            $table->index(['tenant_id', 'actor_id', 'occurred_at'], 'audit_events_actor_idx');
            $table->index(['tenant_id', 'action', 'occurred_at'], 'audit_events_action_idx');
        });

        $schema->create('audit_heads', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->unsignedBigInteger('last_seq');
            $table->char('last_hash', 64);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('audit_heads');
        $schema->dropIfExists('audit_events');
        $schema->dropIfExists('audit_outbox');
    }
};
