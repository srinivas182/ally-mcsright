<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AI first responder (specs/grow/06, Sprint 16B-1): per-brokerage settings and one AI thread per lead, holding
 * what it learned (qualification), the hand-off summary and the suggested next message (encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('crm_ai_responder_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('assistant_name', 40)->default('Ally');
            $table->string('tone', 15)->default('friendly'); // friendly | professional
            $table->unsignedTinyInteger('max_messages')->default(6);
            $table->json('sources')->nullable(); // lead source ulids; null: every source
            $table->text('knowledge')->nullable(); // brokerage facts the assistant may use
            $table->timestamps();
        });

        $cell->create('crm_ai_threads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('status', 12)->default('active'); // active | handed_off | stopped
            $table->string('channel', 5)->nullable();
            $table->unsignedTinyInteger('messages_sent')->default(0);
            $table->text('qualification')->nullable(); // encrypted JSON
            $table->string('handoff_reason', 30)->nullable();
            $table->dateTime('handed_off_at')->nullable();
            $table->text('summary')->nullable(); // encrypted
            $table->text('next_message')->nullable(); // encrypted
            $table->dateTime('last_reply_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'contact_id', 'status']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_ai_threads');
        $cell->dropIfExists('crm_ai_responder_settings');
    }
};
