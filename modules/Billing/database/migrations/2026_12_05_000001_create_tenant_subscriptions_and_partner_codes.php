<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * The brokerage's flat-fee subscription and partner join codes (specs/core/07, Sprint 10A-2b).
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->create('billing_tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('package_id');
            $table->string('interval', 5); // month, year
            $table->string('status', 10);   // active, grace, ended
            $table->unsignedBigInteger('price_cents');
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('grace_until')->nullable();
            $table->string('gateway_ref', 120)->nullable();
            $table->string('customer_ref', 120)->nullable();
            $table->timestamps();
        });

        $control->table('tenants', function (Blueprint $table): void {
            $table->string('partner_code', 12)->nullable()->unique();
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('tenants', function (Blueprint $t): void {
            $t->dropUnique(['partner_code']);
            $t->dropColumn('partner_code');
        });
        $control->dropIfExists('billing_tenant_subscriptions');
    }
};
