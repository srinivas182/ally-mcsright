<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX11: a check of every signed MLS agreement before it goes to the board (rules, then AI), and a watch on each
 * board's own agreement form so HQ hears when the board publishes a new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('idx_subscriptions', function (Blueprint $table): void {
            $table->string('check_result', 10)->nullable(); // clear | issues
            $table->json('check_issues')->nullable(); // [{text, for: brokerage|hq}]
            $table->text('check_note')->nullable(); // the note to the brokerage, written for HQ to send or change
            $table->string('check_source', 10)->nullable(); // ai | rules
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('agreement_version')->nullable(); // the board form version that was signed
            $table->text('client_note')->nullable()->change(); // room for the full "what to fix" note
        });
        Schema::connection(Connections::control())->table('idx_boards', function (Blueprint $table): void {
            $table->string('agreement_url', 500)->nullable(); // where the board publishes its form
            $table->string('agreement_hash', 64)->nullable();
            $table->unsignedInteger('agreement_version')->default(1);
            $table->string('agreement_pending_path', 300)->nullable(); // a newer version found by the watch
            $table->timestamp('agreement_pending_at')->nullable();
            $table->timestamp('agreement_watched_at')->nullable();
            $table->string('agreement_watch_note', 300)->nullable();
            $table->timestamp('agreement_confirmed_at')->nullable(); // HQ confirmed the form is current
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('idx_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['check_result', 'check_issues', 'check_note', 'check_source', 'checked_at', 'agreement_version']);
            $table->string('client_note', 300)->nullable()->change();
        });
        Schema::connection(Connections::control())->table('idx_boards', function (Blueprint $table): void {
            $table->dropColumn(['agreement_url', 'agreement_hash', 'agreement_version', 'agreement_pending_path', 'agreement_pending_at', 'agreement_watched_at',
                'agreement_watch_note', 'agreement_confirmed_at']);
        });
    }
};
