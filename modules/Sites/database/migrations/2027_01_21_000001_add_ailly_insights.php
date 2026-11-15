<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 17D-2 (specs/websites/12): the closing brief on website leads, the brokerage's own answers, questions AIlly
 * could not answer, hot-lead alerts, and what the content guard removed from each answer (the Fair Housing audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->table('site_visitors', function (Blueprint $table): void {
            $table->json('brief')->nullable()->after('summary_at');
            $table->timestamp('brief_at')->nullable()->after('brief');
        });

        $cell->table('ailly_messages', function (Blueprint $table): void {
            $table->json('flags')->nullable()->after('cached'); // sentences the guard removed, and why
            $table->string('answered_from', 10)->nullable()->after('flags'); // ai, cache, answers
        });

        $cell->table('ailly_conversations', function (Blueprint $table): void {
            $table->timestamp('hot_alerted_at')->nullable()->after('lead_score');
        });

        $cell->create('ailly_answers', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('question', 200);
            $table->text('answer');
            $table->string('keywords', 400); // normalised words of the question, for matching
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $cell->create('ailly_unanswered', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->char('question_hash', 64);
            $table->string('question', 300);
            $table->unsignedInteger('times')->default(1);
            $table->timestamp('last_asked_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'question_hash']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ailly_unanswered');
        $cell->dropIfExists('ailly_answers');
        $cell->table('ailly_conversations', fn (Blueprint $table) => $table->dropColumn('hot_alerted_at'));
        $cell->table('ailly_messages', fn (Blueprint $table) => $table->dropColumn(['flags', 'answered_from']));
        $cell->table('site_visitors', fn (Blueprint $table) => $table->dropColumn(['brief', 'brief_at']));
    }
};
