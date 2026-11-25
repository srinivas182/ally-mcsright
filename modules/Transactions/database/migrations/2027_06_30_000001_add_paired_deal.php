<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * DA1: a dual deal (the brokerage on both sides) is the seller side plus a linked buyer side, so each side has its own
 * commission, agents and calculation, and counts as its own side in reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('paired_deal_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->dropIndex(['paired_deal_id']);
            $table->dropColumn('paired_deal_id');
        });
    }
};
