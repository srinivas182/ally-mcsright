<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Pricing and entitlements for the brokerage-partner, agent-pays model (specs/core/07, Sprint 10A).
 * Control database: what HQ sells. Cell: each brokerage's prices and each agent's plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->create('billing_packages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 80);
            $table->unsignedBigInteger('monthly_cents');
            $table->unsignedBigInteger('yearly_cents');
            $table->unsignedInteger('ailly_conversations')->default(500);
            $table->json('features');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $control->create('billing_agent_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->unsignedBigInteger('list_cents');
            $table->unsignedBigInteger('floor_cents');
            $table->json('features');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $control->create('billing_commission_tiers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('min_paying_agents');
            $table->decimal('percent', 5, 2);
        });

        $control->create('billing_price_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agent_plan_id');
            $table->string('field', 10);
            $table->unsignedBigInteger('old_cents');
            $table->unsignedBigInteger('new_cents');
            $table->dateTime('effective_at');
            $table->timestamps();
        });

        $control->table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('billing_package_id')->nullable();
            $table->string('billing_model', 16)->default('brokerage_pays');
            $table->string('no_plan_policy', 8)->default('minimal');
        });

        $cell = Schema::connection(Connections::cell());

        $cell->create('billing_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('agent_plan_id');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('pending_price_cents')->nullable();
            $table->dateTime('pending_from')->nullable();
            $table->boolean('offered')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'agent_plan_id'], 'billing_plan_prices_unq');
        });

        $cell->create('billing_agent_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('agent_plan_id');
            $table->string('status', 10);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('subsidy_cents')->default(0);
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('grace_until')->nullable();
            $table->string('gateway_ref', 120)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status'], 'billing_subs_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('billing_agent_subscriptions');
        Schema::connection(Connections::cell())->dropIfExists('billing_plan_prices');
        $control = Schema::connection(Connections::control());
        $control->table('tenants', fn (Blueprint $table) => $table->dropColumn(['billing_package_id', 'billing_model', 'no_plan_policy']));
        foreach (['billing_price_notices', 'billing_commission_tiers', 'billing_agent_plans', 'billing_packages'] as $t) {
            $control->dropIfExists($t);
        }
    }
};
