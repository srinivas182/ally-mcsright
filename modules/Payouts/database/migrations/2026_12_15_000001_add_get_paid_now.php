<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Get paid now (specs/finance/02, addition 6): HQ fee limits, brokerage settings, instant runs and fees. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('pay_instant_limits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('max_flat_cents')->default(2_500);
            $table->decimal('max_percent', 5, 2)->default(2.00);
            $table->timestamps();
        });

        $cell = Schema::connection(Connections::cell());
        $cell->table('pay_settings', function (Blueprint $table): void {
            $table->boolean('instant_enabled')->default(false);
            $table->unsignedInteger('instant_fee_flat_cents')->default(0);
            $table->decimal('instant_fee_percent', 5, 2)->default(0);
            $table->unsignedInteger('instant_min_cents')->default(10_000);
        });
        $cell->table('pay_runs', function (Blueprint $table): void {
            $table->string('kind', 8)->default('regular'); // regular, instant
            $table->unsignedBigInteger('requested_by')->nullable();
        });
        $cell->table('pay_run_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_cents')->default(0);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('pay_run_lines', fn (Blueprint $t) => $t->dropColumn('fee_cents'));
        $cell->table('pay_runs', fn (Blueprint $t) => $t->dropColumn(['kind', 'requested_by']));
        $cell->table('pay_settings', fn (Blueprint $t) => $t->dropColumn(['instant_enabled', 'instant_fee_flat_cents', 'instant_fee_percent', 'instant_min_cents']));
        Schema::connection(Connections::control())->dropIfExists('pay_instant_limits');
    }
};
