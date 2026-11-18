<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * VO1 (specs/grow/14): spoken AI conversations through the voice provider HQ chose (Retell, Vapi or Bland), with the
 * transcript and the agent's hand-off summary; and agents' calls placed through RightAlly, recorded with everyone's
 * consent, written down and summarised (the audio is deleted once written down).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_ai_calls', function (Blueprint $table): void {
            $table->string('provider', 12)->nullable();
            $table->string('provider_call_id', 100)->nullable();
            $table->text('summary')->nullable(); // encrypted
            $table->mediumText('transcript')->nullable(); // encrypted
            $table->unsignedTinyInteger('polls')->default(0);
        });
        $cell->create('crm_recorded_calls', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 12)->default('calling'); // calling, recorded, written, no_recording, failed
            $table->string('call_sid', 64)->nullable();
            $table->string('recording_sid', 64)->nullable();
            $table->string('transcript_job', 64)->nullable(); // a transcripts provider that works in the background
            $table->unsignedInteger('seconds')->nullable();
            $table->mediumText('transcript')->nullable(); // encrypted
            $table->text('summary')->nullable(); // encrypted
            $table->string('reason', 200)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_recorded_calls');
        $cell->table('crm_ai_calls', fn (Blueprint $table) => $table->dropColumn(['provider', 'provider_call_id', 'summary', 'transcript', 'polls']));
    }
};
