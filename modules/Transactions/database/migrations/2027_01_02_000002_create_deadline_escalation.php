<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ deadlines (specs/ai/02, Sprint 15D): the brokerage's escalation ladder, each agent's consent to
 * texts and automated calls and private calendar link, and a record of every alert sent (each once).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('tx_deadline_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->primary('tenant_id');
            $table->unsignedTinyInteger('email_days')->default(2); // daily email digest window
            $table->unsignedTinyInteger('text_days')->default(1); // text the agent this many days before
            $table->boolean('call_on_due')->default(true); // automated call on the due day
            $table->boolean('broker_on_missed')->default(true);
            $table->unsignedSmallInteger('closing_blocker_hours')->default(48); // blockers this close to closing go to the broker
            $table->timestamps();
        });

        $schema->create('tx_alert_prefs', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->dateTime('sms_consent_at')->nullable();
            $table->dateTime('voice_consent_at')->nullable();
            $table->char('calendar_token', 40)->nullable()->unique();
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
        });

        $schema->create('tx_date_alerts', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('date_id')->constrained('tx_dates')->cascadeOnDelete();
            $table->string('step', 10); // text, due, missed
            $table->string('channel', 6); // sms, voice, email
            $table->dateTime('sent_at');
            $table->primary(['date_id', 'step', 'channel']);
        });

        $schema->table('tx_findings', function (Blueprint $table): void {
            $table->dateTime('broker_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_findings', fn (Blueprint $t) => $t->dropColumn('broker_alerted_at'));
        $schema->dropIfExists('tx_date_alerts');
        $schema->dropIfExists('tx_alert_prefs');
        $schema->dropIfExists('tx_deadline_settings');
    }
};
