<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Partner earnings (specs/core/07, Sprint 10A-3): money collected from agents, refunds, monthly
 * statements, and Stripe Connect payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->create('billing_collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('agent_plan_id');
            $table->string('invoice_ref', 120)->unique();
            $table->unsignedBigInteger('amount_cents');   // collected, excluding tax
            $table->unsignedBigInteger('price_cents');    // brokerage price when paid
            $table->unsignedBigInteger('floor_cents');    // floor in force when paid
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->unsignedBigInteger('refund_settled_cents')->default(0); // already reflected on a statement
            $table->char('month', 7);                      // YYYY-MM
            $table->dateTime('paid_at');
            $table->timestamps();
        });

        $control->create('billing_partner_statements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->char('month', 7);
            $table->unsignedInteger('paying_agents');
            $table->decimal('percent', 5, 2);
            $table->bigInteger('commission_cents');
            $table->bigInteger('markup_cents');
            $table->bigInteger('adjustments_cents'); // refunds and chargebacks (negative)
            $table->bigInteger('carried_in_cents');
            $table->bigInteger('payable_cents');
            $table->string('status', 10); // open, paid, carried
            $table->string('payout_ref', 120)->nullable();
            $table->json('lines');
            $table->timestamps();
            $table->unique(['tenant_id', 'month'], 'billing_statements_month_unq');
        });

        $control->table('tenants', function (Blueprint $table): void {
            $table->string('connect_account', 60)->nullable();
            $table->boolean('connect_ready')->default(false);
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('tenants', fn (Blueprint $t) => $t->dropColumn(['connect_account', 'connect_ready']));
        $control->dropIfExists('billing_partner_statements');
        $control->dropIfExists('billing_collections');
    }
};
