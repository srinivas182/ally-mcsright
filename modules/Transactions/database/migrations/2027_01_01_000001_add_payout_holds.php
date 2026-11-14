<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ money and wire fraud (specs/ai/02, Sprint 15C): a deal's payouts are held while a money or wire
 * blocker is open; when an automatic payout waited on the hold, who triggered it, so it resumes when cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->string('payout_hold_reason', 400)->nullable();
            $table->unsignedBigInteger('payout_waiting_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', fn (Blueprint $t) => $t->dropColumn(['payout_hold_reason', 'payout_waiting_by']));
    }
};
