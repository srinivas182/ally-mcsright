<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Payment settings (HQ), the agent-pays default and per-brokerage exceptions, and processed
 * gateway events (specs/core/07, Sprint 10A-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->create('billing_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 4)->default('test'); // test, live
            $table->text('test_keys')->nullable();      // encrypted json: publishable, secret, webhook_secret
            $table->text('live_keys')->nullable();
            $table->boolean('agent_pays_default')->default(true);
            $table->boolean('automatic_tax')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $control->create('billing_gateway_events', function (Blueprint $table): void {
            $table->string('id', 120)->primary();
            $table->string('type', 80);
            $table->dateTime('processed_at');
        });

        $control->table('tenants', function (Blueprint $table): void {
            $table->boolean('billing_exception')->default(false); // HQ: this brokerage pays for its agents
            $table->string('billing_exception_reason', 300)->nullable();
        });

        Schema::connection(Connections::cell())->table('billing_agent_subscriptions', function (Blueprint $table): void {
            $table->string('customer_ref', 120)->nullable();
            $table->boolean('sponsored')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('billing_agent_subscriptions', fn (Blueprint $t) => $t->dropColumn(['customer_ref', 'sponsored']));
        Schema::connection(Connections::control())->table('tenants', fn (Blueprint $t) => $t->dropColumn(['billing_exception', 'billing_exception_reason']));
        Schema::connection(Connections::control())->dropIfExists('billing_gateway_events');
        Schema::connection(Connections::control())->dropIfExists('billing_settings');
    }
};
