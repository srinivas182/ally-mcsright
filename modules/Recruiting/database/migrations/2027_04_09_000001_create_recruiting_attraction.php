<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * RC2, the recruiting attraction engine (specs/grow/17): a likely-to-move score with its reasons, recruiting
 * campaigns (email with an unsubscribe link; texts only with consent), the careers page and its applications, and
 * agents' own words for it (shown only with their consent).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('rec_mls_agents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('move_score')->default(0);
            $table->json('move_reasons')->nullable();
            $table->index(['tenant_id', 'ours', 'move_score']);
        });
        $cell->table('rec_recruits', function (Blueprint $table): void {
            $table->dateTime('email_opt_out_at')->nullable();
            $table->dateTime('text_consent_at')->nullable();
            $table->string('text_consent_wording', 400)->nullable();
            $table->dateTime('text_opt_out_at')->nullable();
            $table->json('application')->nullable(); // from the careers page: licence, deals a year, message
        });
        $cell->create('rec_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 120);
            $table->json('steps'); // [{day, channel: email|text, subject, body}]
            $table->string('status', 10)->default('active'); // active, paused
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        $cell->create('rec_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('recruit_id');
            $table->unsignedSmallInteger('next_step')->default(0);
            $table->dateTime('next_at')->nullable();
            $table->string('status', 10)->default('active'); // active, finished, stopped
            $table->string('stopped_reason', 120)->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'campaign_id', 'recruit_id']);
            $table->index(['tenant_id', 'status', 'next_at']);
        });
        $cell->create('rec_stories', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('quote', 600);
            $table->boolean('show_deals')->default(false);
            $table->dateTime('consented_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        $cell->table('rec_settings', function (Blueprint $table): void {
            $table->json('careers')->nullable(); // headline, intro, reasons, calculator, live
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('rec_stories');
        $cell->dropIfExists('rec_enrollments');
        $cell->dropIfExists('rec_campaigns');
        $cell->table('rec_settings', fn (Blueprint $t) => $t->dropColumn('careers'));
        $cell->table('rec_recruits', fn (Blueprint $t) => $t->dropColumn(['email_opt_out_at', 'text_consent_at', 'text_consent_wording', 'text_opt_out_at', 'application']));
        $cell->table('rec_mls_agents', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'ours', 'move_score']);
            $t->dropColumn(['move_score', 'move_reasons']);
        });
    }
};
