<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Automatic payouts on closing (specs/finance/02): the owner's standing rule. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('pay_settings', function (Blueprint $table): void {
            $table->boolean('auto_pay_enabled')->default(false);
            $table->unsignedBigInteger('auto_pay_limit_cents')->default(1_500_000);
            $table->boolean('auto_pay_same_day')->default(false);
            $table->unsignedBigInteger('auto_pay_set_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('pay_settings', fn (Blueprint $t) => $t->dropColumn(['auto_pay_enabled', 'auto_pay_limit_cents', 'auto_pay_same_day', 'auto_pay_set_by']));
    }
};
