<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18B: buying RightAlly credits (specs/billing/04). HQ's credit packs, and each brokerage's purchases. A paid
 * purchase is also a lot: its credits are used oldest-first after the month's included credits, and whatever is
 * left 12 months after payment expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('credit_packs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 80);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_cents');
            $table->string('audience', 10)->default('both'); // brokerage, agent, both
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $cell = Schema::connection(Connections::cell());
        $cell->create('credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('account_user_id')->nullable(); // null: the brokerage pool
            $table->unsignedBigInteger('buyer_id');
            $table->unsignedBigInteger('pack_id');
            $table->string('pack_name', 80);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_cents');
            $table->string('status', 10)->default('pending'); // pending, paid, refunded
            $table->string('gateway_session', 120)->nullable();
            $table->string('gateway_payment', 120)->nullable();
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('remaining')->default(0);
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        $cell->table('credit_policies', function (Blueprint $table): void {
            $table->boolean('agents_can_buy')->default(true)->after('agent_monthly_cap');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('credit_policies', fn (Blueprint $table) => $table->dropColumn('agents_can_buy'));
        $cell->dropIfExists('credit_purchases');
        Schema::connection(Connections::control())->dropIfExists('credit_packs');
    }
};
