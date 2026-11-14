<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Unified inbox (specs/grow/05, Sprint 16A-2): one conversation per contact holding texts and emails both ways,
 * message templates, and text-for-info sign codes. Message bodies are encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('crm_conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->string('reply_token', 40)->unique();
            $table->dateTime('last_message_at')->nullable();
            $table->string('last_direction', 3)->nullable();
            $table->string('preview', 160)->nullable(); // encrypted
            $table->boolean('unread')->default(false);
            $table->dateTime('needs_reply_since')->nullable();
            $table->dateTime('nudged_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'assigned_user_id', 'last_message_at'], 'crm_conversations_assignee_idx');
            $table->index(['tenant_id', 'needs_reply_since']);
        });

        $cell->create('crm_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('channel', 5); // sms | email
            $table->string('direction', 3); // in | out
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body'); // encrypted
            $table->string('status', 10); // sent | failed | received
            $table->string('provider_id', 120)->nullable();
            $table->string('error', 200)->nullable();
            $table->boolean('ai_drafted')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'conversation_id', 'id']);
            $table->index(['tenant_id', 'provider_id']);
        });

        $cell->create('crm_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable(); // null: shared with the brokerage
            $table->string('name', 80);
            $table->string('channel', 5); // sms | email
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });

        $cell->create('crm_sign_codes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('code', 12);
            $table->unsignedBigInteger('user_id');
            $table->string('property', 200);
            $table->string('details', 400);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('texts')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        $cell->table('crm_leads', function (Blueprint $table): void {
            $table->dateTime('engaged_at')->nullable();
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_leads', fn (Blueprint $table) => $table->dropColumn('engaged_at'));
        $cell->dropIfExists('crm_sign_codes');
        $cell->dropIfExists('crm_templates');
        $cell->dropIfExists('crm_messages');
        $cell->dropIfExists('crm_conversations');
    }
};
