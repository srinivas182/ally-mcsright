<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 17D-1: AIlly, the website assistant (specs/websites/11). Conversations with visitors, their messages (kept
 * for the brokerage's transcripts), and answers to common first questions reused for free for a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('ailly_conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('visitor_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('page', 300)->nullable();
            $table->string('status', 10)->default('open');   // open, ended
            $table->string('outcome', 12)->default('none');  // none, lead, call, showing
            $table->unsignedSmallInteger('visitor_messages')->default(0);
            $table->unsignedSmallInteger('ai_replies')->default(0);
            $table->unsignedSmallInteger('cached_replies')->default(0);
            $table->unsignedSmallInteger('off_topic')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedTinyInteger('lead_score')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'outcome']);
        });

        $cell->create('ailly_messages', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('role', 8); // visitor, ailly
            $table->text('body');
            $table->json('listings')->nullable();
            $table->boolean('cached')->default(false);
            $table->dateTime('created_at');
        });

        $cell->create('ailly_answer_cache', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('site_id');
            $table->char('question_hash', 64);
            $table->text('answer');
            $table->string('next_step', 12)->default('none');
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'question_hash']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ailly_answer_cache');
        $cell->dropIfExists('ailly_messages');
        $cell->dropIfExists('ailly_conversations');
    }
};
