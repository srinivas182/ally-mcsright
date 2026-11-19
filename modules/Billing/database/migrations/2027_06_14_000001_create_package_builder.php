<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * PB1 (package builder), control database: which modules a package includes, its seats and included usage, the
 * markup it was priced at and the cost behind it; HQ's pricing rules (markup, minimum markup, card fees); and the cost
 * items the modules need that were not on the cost list yet. Costs marked "enter your real cost" are placeholders.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('billing_packages', function (Blueprint $table): void {
            $table->json('modules')->nullable();
            $table->unsignedInteger('seats')->default(1);
            $table->json('usage')->nullable();
            $table->unsignedSmallInteger('markup_pct')->nullable();
            $table->string('price_reason', 300)->nullable();
            $table->json('quote')->nullable();
        });
        $control->table('credit_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('markup_pct')->default(70);
            $table->unsignedSmallInteger('min_markup_pct')->default(40);
            $table->unsignedSmallInteger('typical_use_pct')->default(50);
            $table->unsignedSmallInteger('card_fee_bp')->default(290);
            $table->unsignedSmallInteger('card_fee_fixed_cents')->default(30);
            $table->unsignedSmallInteger('yearly_months')->default(10);
        });

        $now = now();
        foreach ([
            ['platform.seat', 'Platform cost per person (servers, storage, support)', 'person per month', 'seat', 200_000_000, null, 'Placeholder: enter your real cost per person per month.'],
            ['transcripts.minute', 'Call and appointment transcript', 'minute', 'usage', 430_000, 'https://deepgram.com/pricing', 'Deepgram Nova-3, pay as you go'],
            ['ai_voice.minute', 'AI voice call', 'minute', 'usage', 7_000_000, 'https://www.retellai.com/pricing', 'Retell AI, typical all-in per minute'],
            ['storage.gb', 'File storage', 'GB per month', 'usage', 2_300_000, 'https://aws.amazon.com/s3/pricing/', 'Amazon S3 Standard'],
        ] as [$key, $label, $unit, $kind, $cost, $url, $note]) {
            DB::connection(Connections::control())->table('cost_catalog')->insertOrIgnore(['key' => $key, 'label' => $label, 'unit' => $unit, 'kind' => $kind,
                'cost_microcents' => $cost, 'free_units_monthly' => 0, 'source_url' => $url, 'note' => $note, 'checked_at' => $url !== null ? '2026-11-19' : null,
                'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('billing_packages', fn (Blueprint $table) => $table->dropColumn(['modules', 'seats', 'usage', 'markup_pct', 'price_reason', 'quote']));
        $control->table('credit_settings', fn (Blueprint $table) => $table->dropColumn(['markup_pct', 'min_markup_pct', 'typical_use_pct', 'card_fee_bp', 'card_fee_fixed_cents', 'yearly_months']));
        DB::connection(Connections::control())->table('cost_catalog')->whereIn('key', ['platform.seat', 'transcripts.minute', 'ai_voice.minute', 'storage.gb'])->delete();
    }
};
