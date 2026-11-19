<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * PB3, control database: the referral rewards HQ can change. A person (cash) or a brokerage (credit on its own bill)
 * earns a share of what a brokerage they referred pays in its first year; both start at 20%.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection(Connections::control())->table('credit_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('referral_person_pct')->default(20);
            $table->unsignedSmallInteger('referral_brokerage_pct')->default(20);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('credit_settings', fn (Blueprint $table) => $table->dropColumn(['referral_person_pct', 'referral_brokerage_pct']));
    }
};
