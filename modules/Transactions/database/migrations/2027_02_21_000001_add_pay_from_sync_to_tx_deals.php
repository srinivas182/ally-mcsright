<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Close there, paid here (specs/connect/12, C5a-3). A deal that closes in dotloop, SkySlope or Lone Wolf is paid in
 * RightAlly automatically. These columns hold the broker's overrides for the deal, where its money stands (paid,
 * reversed, or waiting for a GCI or a fix), why, and what was paid on, so a change in the other app pays it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->json('pay_overrides')->nullable();
            $table->string('money_status', 16)->nullable(); // paid, reversed, needs_gci, needs_review
            $table->string('money_note', 300)->nullable();
            $table->char('paid_fingerprint', 64)->nullable();
            $table->boolean('agent_paid_outside')->default(false); // the agent's own commission was paid outside RightAlly
            $table->index(['tenant_id', 'money_status'], 'tx_deals_money_idx');
        });
        Schema::connection(Connections::cell())->table('tx_settings', function (Blueprint $table): void {
            // Agents' commission on deals from another app: "outside" (at closing or by the old back office; nothing
            // to pay here) or "payouts" (through RightAlly payouts once the money received is recorded).
            $table->string('synced_commission', 10)->default('outside');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->dropIndex('tx_deals_money_idx');
            $table->dropColumn(['pay_overrides', 'money_status', 'money_note', 'paid_fingerprint', 'agent_paid_outside']);
        });
        Schema::connection(Connections::cell())->table('tx_settings', fn (Blueprint $t) => $t->dropColumn('synced_commission'));
    }
};
