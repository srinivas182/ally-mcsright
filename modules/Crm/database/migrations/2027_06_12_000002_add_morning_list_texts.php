<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SX6: the agent's own opt-in to a morning text with today's list, and the day it was last sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_alert_prefs', function (Blueprint $table): void {
            $table->dateTime('morning_text_at')->nullable();
            $table->date('morning_text_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_alert_prefs', function (Blueprint $table): void {
            $table->dropColumn(['morning_text_at', 'morning_text_sent_on']);
        });
    }
};
