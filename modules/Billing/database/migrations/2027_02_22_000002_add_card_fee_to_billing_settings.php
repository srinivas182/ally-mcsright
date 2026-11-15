<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Card processing fee (specs/connect/14): when a paid app's add-on is paid by card instead of credits, the card
 * price includes the card processor's fee (Stripe's standard 2.9% + 30¢ by default), so RightAlly keeps the same
 * amount either way. HQ can change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('billing_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('card_fee_bps')->default(290); // 2.90%
            $table->unsignedSmallInteger('card_fee_cents')->default(30);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('billing_settings', fn (Blueprint $t) => $t->dropColumn(['card_fee_bps', 'card_fee_cents']));
    }
};
