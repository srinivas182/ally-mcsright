<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Lead scoring with reasons, monthly spend per lead source or channel (return on spend) and the brokerage's speed
 * targets (specs/grow/07, Sprint 16B-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_leads', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('score_label', 5)->nullable(); // hot | warm | cold
            $table->json('score_reasons')->nullable();
            $table->dateTime('scored_at')->nullable();
            $table->dateTime('hot_alerted_at')->nullable();
            $table->index(['tenant_id', 'score_label'], 'crm_leads_label_idx');
        });
        $cell->create('crm_lead_spend', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->date('month'); // first day of the month
            $table->string('key', 40); // a lead source (zillow, facebook, …) or channel:<channel>
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'month', 'key'], 'crm_lead_spend_unique');
        });
        $cell->table('crm_lead_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('target_minutes')->default(5);
            $table->unsignedTinyInteger('target_percent')->default(80);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_lead_settings', fn (Blueprint $table) => $table->dropColumn(['target_minutes', 'target_percent']));
        $cell->dropIfExists('crm_lead_spend');
        $cell->table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex('crm_leads_label_idx');
            $table->dropColumn(['score', 'score_label', 'score_reasons', 'scored_at', 'hot_alerted_at']);
        });
    }
};
