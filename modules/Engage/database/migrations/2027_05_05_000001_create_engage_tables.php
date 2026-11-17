<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** EN1 (specs/engage/01): coach notes, the database assistant, and recognition messages with consented voices. */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection(Connections::cell());
        $s->create('engage_coach_notes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->date('day');
            $table->text('note'); // encrypted JSON
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'day']);
        });
        $s->create('engage_assistants', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->boolean('active')->default(false);
            $table->unsignedTinyInteger('weekly')->default(5);
            $table->string('audience', 16)->default('past_clients'); // past_clients, sphere, both
            $table->string('about', 300)->nullable(); // what the agent wants to mention this season
            $table->dateTime('last_run_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        $s->create('engage_checkins', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('status', 10)->default('draft'); // draft, sent, replied, skipped
            $table->string('subject', 200);
            $table->text('body'); // encrypted
            $table->string('intent', 16)->nullable(); // buy, sell, refer, question, not_now, unsubscribe, other
            $table->text('brief')->nullable(); // encrypted
            $table->text('suggested_reply')->nullable(); // encrypted
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'contact_id', 'status']);
        });
        $s->create('engage_voices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->text('voice_ref')->nullable(); // encrypted vendor id; null once deleted
            $table->string('status', 10)->default('active'); // active, deleted
            $table->string('consent_name', 120);
            $table->text('consent_text');
            $table->string('consent_ip', 45)->nullable();
            $table->char('recording_sha256', 64);
            $table->dateTime('consent_at');
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
        });
        $s->create('engage_recognitions', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->string('occasion', 40);
            $table->text('text')->nullable();
            $table->string('audio_path', 200)->nullable();
            $table->string('audio_kind', 10)->default('none'); // none, recorded, ai_voice
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('played_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'to_user_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection(Connections::cell());
        foreach (['engage_recognitions', 'engage_voices', 'engage_checkins', 'engage_assistants', 'engage_coach_notes'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
