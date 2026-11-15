<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** RightAlly Boost (specs/ads/02): ads paid with credits from RightAlly's ad account, refunds and the stop-loss. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->string('billing', 10)->default('own')->after('channel');       // own: the person's ad account; boost: credits
            $table->unsignedBigInteger('connection_id')->nullable()->change();     // a Boost has no connection of its own
            $table->unsignedInteger('margin_bps')->default(0);                     // RightAlly's margin when it was made (Boost)
            $table->unsignedBigInteger('credits_reserved')->default(0);
            $table->unsignedBigInteger('credits_refunded')->default(0);
            $table->boolean('stop_loss')->default(true);
            $table->string('stopped_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['billing', 'margin_bps', 'credits_reserved', 'credits_refunded', 'stop_loss', 'stopped_reason']);
        });
    }
};
