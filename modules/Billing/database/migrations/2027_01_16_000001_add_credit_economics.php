<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18A-2a: pricing credits from real costs (specs/billing/02). Margin targets, cost estimates per action,
 * a catalog of non-AI costs (texts, calls, maps, email, hosting), audio prices for speech-to-text models, and
 * a reason when a package is saved below the minimum margin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->table('credit_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('target_margin_pct')->default(60)->after('enforce');
            $table->unsignedTinyInteger('floor_margin_pct')->default(40)->after('target_margin_pct');
            // Share of a package's price HQ is willing to spend on usage when suggesting included credits.
            $table->unsignedTinyInteger('usage_budget_pct')->default(20)->after('floor_margin_pct');
        });

        $control->table('credit_rate_card', function (Blueprint $table): void {
            // Expected size of one use, for planning before there is real data.
            $table->unsignedInteger('est_tokens_in')->nullable()->after('automation');
            $table->unsignedInteger('est_tokens_out')->nullable()->after('est_tokens_in');
            $table->unsignedInteger('est_audio_seconds')->nullable()->after('est_tokens_out');
        });

        $control->table('ai_models', function (Blueprint $table): void {
            // Speech-to-text is billed per minute of audio, not per token.
            $table->unsignedBigInteger('audio_cost_per_minute_microcents')->default(0)->after('cache_read_cost_per_mtok_cents');
            $table->date('prices_checked_at')->nullable()->after('audio_cost_per_minute_microcents');
        });

        $control->create('cost_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 120);
            $table->string('unit', 40);                 // e.g. "text segment", "call minute", "map load", "brokerage per month"
            $table->string('kind', 10);                 // usage (per unit) or monthly (per brokerage per month)
            $table->unsignedBigInteger('cost_microcents');
            $table->unsignedInteger('free_units_monthly')->default(0); // free tier on RightAlly's account (all brokerages together)
            $table->string('source_url', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->date('checked_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $control->table('billing_packages', function (Blueprint $table): void {
            $table->string('margin_override_reason', 255)->nullable()->after('included_credits');
        });

        // Starting prices, checked against the vendors' published US prices in November 2026. HQ confirms or edits them.
        $now = CarbonImmutable::now();
        $checked = '2026-11-14';
        DB::connection(Connections::control())->table('cost_catalog')->insert([
            ['key' => 'twilio.sms_segment', 'label' => 'Text message (per segment)', 'unit' => 'text segment', 'kind' => 'usage', 'cost_microcents' => 1_330_000, 'free_units_monthly' => 0,
                'source_url' => 'https://www.twilio.com/en-us/sms/pricing/us', 'note' => 'Twilio $0.0083 + carrier fee up to $0.005', 'checked_at' => $checked, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'twilio.mms', 'label' => 'Picture message (MMS)', 'unit' => 'message', 'kind' => 'usage', 'cost_microcents' => 3_200_000, 'free_units_monthly' => 0,
                'source_url' => 'https://www.twilio.com/en-us/sms/pricing/us', 'note' => 'Twilio $0.022 + carrier fee up to $0.01', 'checked_at' => $checked, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'twilio.call_minute', 'label' => 'Phone call (per minute, outbound)', 'unit' => 'call minute', 'kind' => 'usage', 'cost_microcents' => 1_400_000, 'free_units_monthly' => 0,
                'source_url' => 'https://www.twilio.com/voice/pricing/us', 'note' => 'Twilio $0.014 per minute to US local numbers', 'checked_at' => $checked, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'mapbox.map_load', 'label' => 'Website map load', 'unit' => 'map load', 'kind' => 'usage', 'cost_microcents' => 500_000, 'free_units_monthly' => 50_000,
                'source_url' => 'https://www.mapbox.com/pricing/', 'note' => 'First 50,000 a month free on our account, then $5 per 1,000', 'checked_at' => $checked, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'postmark.email', 'label' => 'Email sent', 'unit' => 'email', 'kind' => 'usage', 'cost_microcents' => 180_000, 'free_units_monthly' => 0,
                'source_url' => 'https://postmarkapp.com/pricing', 'note' => 'Postmark $1.80 per 1,000 over the plan', 'checked_at' => $checked, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hosting.brokerage', 'label' => 'Hosting, storage and backups', 'unit' => 'brokerage per month', 'kind' => 'monthly', 'cost_microcents' => 0, 'free_units_monthly' => 0,
                'source_url' => null, 'note' => 'Enter your average infrastructure cost per brokerage', 'checked_at' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('billing_packages', fn (Blueprint $table) => $table->dropColumn('margin_override_reason'));
        $control->dropIfExists('cost_catalog');
        $control->table('ai_models', fn (Blueprint $table) => $table->dropColumn(['audio_cost_per_minute_microcents', 'prices_checked_at']));
        $control->table('credit_rate_card', fn (Blueprint $table) => $table->dropColumn(['est_tokens_in', 'est_tokens_out', 'est_audio_seconds']));
        $control->table('credit_settings', fn (Blueprint $table) => $table->dropColumn(['target_margin_pct', 'floor_margin_pct', 'usage_budget_pct']));
    }
};
