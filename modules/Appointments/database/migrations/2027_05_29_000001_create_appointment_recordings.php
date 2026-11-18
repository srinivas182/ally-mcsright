<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * VO2 (specs/listings/05): in-person appointments recorded with everyone's spoken consent, written down, and turned
 * into notes and private coaching for the agent. The audio is deleted once written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('appt_recordings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('consultation_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 12)->default('recording'); // recording, uploaded, writing, done, failed, deleted
            $table->string('consent_names', 300);
            $table->string('consent_wording', 600);
            $table->string('consent_ip', 45)->nullable();
            $table->dateTime('consent_at');
            $table->unsignedSmallInteger('parts')->default(0);
            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('seconds')->nullable();
            $table->string('transcript_job', 64)->nullable();
            $table->mediumText('transcript')->nullable(); // encrypted
            $table->text('notes')->nullable(); // encrypted JSON: summary, wants, objections, coaching
            $table->unsignedTinyInteger('talk_share')->nullable(); // % of words spoken by the agent
            $table->boolean('shared')->default(false); // the agent shared the coaching with their broker
            $table->string('reason', 200)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('appt_recordings');
    }
};
