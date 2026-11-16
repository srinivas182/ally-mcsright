<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LQ1 (specs/grow/14): Lead-IQ calls new leads within a minute in the brokerage's own name, only with written consent
 * to automated calls, transfers interested people to an agent, and sends a booking link otherwise. The broker sets the
 * voice, the language, what the assistant calls the brokerage, and a monthly limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_ai_responder_settings', function (Blueprint $table): void {
            $table->boolean('voice_enabled')->default(false);
            $table->string('voice', 10)->default('female'); // female, male
            $table->string('voice_language', 4)->default('both'); // en, es, both (English first, press 3 for Spanish)
            $table->string('brand_name', 80)->nullable(); // how the assistant names the brokerage; null: the brokerage's name
            $table->unsignedSmallInteger('voice_monthly_cap')->default(200);
        });
        $cell->create('crm_ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('status', 14)->default('queued'); // queued, calling, transferring, transferred, booking_sent, booked, no_answer, stopped, skipped
            $table->string('reason', 200)->nullable(); // why it was skipped or how it ended, in plain words
            $table->string('lang', 2)->default('en');
            $table->json('agents')->nullable(); // the agents to try, in order
            $table->unsignedTinyInteger('tried')->default(0);
            $table->unsignedBigInteger('agent_id')->nullable(); // the agent who took the call or the booking
            $table->string('call_sid', 64)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_ai_calls');
        $cell->table('crm_ai_responder_settings', fn (Blueprint $table) => $table->dropColumn(['voice_enabled', 'voice', 'voice_language', 'brand_name', 'voice_monthly_cap']));
    }
};
