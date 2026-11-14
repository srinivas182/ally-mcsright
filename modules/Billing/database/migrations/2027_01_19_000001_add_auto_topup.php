<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18B-2 (specs/billing/05): saved cards and automatic top-up for credits, paused website maps counted per
 * brokerage, HQ's platform map-view alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $cell = Schema::connection(Connections::cell());

        // A saved card and its automatic top-up rule, for the brokerage pool (user_id null) or one agent.
        $cell->create('credit_autopay', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('customer_ref', 120)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('threshold')->default(200);
            $table->unsignedBigInteger('pack_id')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->string('last_error', 200)->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        $cell->table('credit_purchases', function (Blueprint $table): void {
            $table->string('source', 10)->default('checkout')->after('status'); // checkout, auto
        });

        // When a brokerage's website map paused for lack of credits, and how many visitors tried to open it since.
        $cell->table('usage_meters', function (Blueprint $table): void {
            $table->dateTime('paused_at')->nullable()->after('over');
            $table->unsignedInteger('missed')->default(0)->after('paused_at');
        });

        $control->table('credit_settings', function (Blueprint $table): void {
            // HQ is emailed once a month when all brokerages together pass this many map views (nothing pauses).
            $table->unsignedInteger('maps_alert_views')->default(60_000)->after('usage_budget_pct');
            $table->char('maps_alert_sent', 7)->nullable()->after('maps_alert_views');
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $cell = Schema::connection(Connections::cell());
        $control->table('credit_settings', fn (Blueprint $table) => $table->dropColumn(['maps_alert_views', 'maps_alert_sent']));
        $cell->table('usage_meters', fn (Blueprint $table) => $table->dropColumn(['paused_at', 'missed']));
        $cell->table('credit_purchases', fn (Blueprint $table) => $table->dropColumn('source'));
        $cell->dropIfExists('credit_autopay');
    }
};
