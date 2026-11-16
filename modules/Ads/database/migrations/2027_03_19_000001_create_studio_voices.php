<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA5b (specs/grow/13): narrated Reels and tours. Stock AI voices for everyone; the agent's own voice only
 * with their written and recorded consent. A voice belongs to one agent, is used only for their own videos, and is
 * deleted when they withdraw, when they leave, or after 12 months unused. Recordings are never kept: only the vendor's
 * voice id (encrypted) and the consent record (what they agreed to, when, from where, and a hash of the recording).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_voices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('vendor', 20)->default('elevenlabs');
            $table->text('voice_ref')->nullable();          // the vendor's voice id, encrypted; null once deleted
            $table->string('status', 10)->default('active'); // active | deleted
            $table->string('consent_name', 120);            // the name the agent typed as their signature
            $table->text('consent_text');                   // exactly what they agreed to
            $table->unsignedSmallInteger('consent_version');
            $table->string('consent_ip', 45)->nullable();
            $table->char('recording_sha256', 64);           // proves which recording was used; the audio itself is not kept
            $table->timestamp('consent_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
        });
        $cell->table('studio_videos', function (Blueprint $table): void {
            $table->text('narration')->nullable();
            $table->string('voice', 60)->nullable();         // stock:{id} | own
            $table->string('narration_error', 200)->nullable();
        });
        DB::connection(Connections::control())->table('cost_catalog')->where('key', 'studio.voice')
            ->update(['note' => 'ElevenLabs text to speech per narration (about 500 characters for a Reel, 1,500 for a tour). Enter your real cost.', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('studio_voices');
        $cell->table('studio_videos', function (Blueprint $table): void {
            $table->dropColumn(['narration', 'voice', 'narration_error']);
        });
    }
};
