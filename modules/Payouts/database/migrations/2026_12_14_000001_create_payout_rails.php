<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Payout rails (specs/finance/02, part 3): HQ enables rails and holds platform credentials; each brokerage
 * picks its rail and links its own bank through the partner; payments are tracked through to completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());

        $control->create('pay_rail_platform', function (Blueprint $table): void {
            $table->string('rail', 20)->primary(); // dwolla, modern_treasury, stripe
            $table->boolean('enabled')->default(false);
            $table->string('mode', 4)->default('test');
            $table->text('test_keys')->nullable(); // encrypted json
            $table->text('live_keys')->nullable();
            $table->timestamps();
        });

        // Webhooks arrive without a brokerage; this maps each payment reference to its brokerage.
        $control->create('pay_rail_refs', function (Blueprint $table): void {
            $table->string('ref', 191)->primary();
            $table->string('rail', 20);
            $table->unsignedBigInteger('tenant_id');
            $table->dateTime('created_at');
        });

        $cell = Schema::connection(Connections::cell());

        $cell->create('pay_rail_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->string('rail', 20)->default('bank_file');
            $table->string('status', 12)->default('ready'); // pending, ready
            $table->boolean('same_day_default')->default(false);
            $table->text('config_sealed')->nullable(); // encrypted json: partner ids and, for Modern Treasury, the brokerage's own key
            $table->timestamps();
            $table->primary('tenant_id');
        });

        $cell->create('pay_payees', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('rail', 20);
            $table->unsignedBigInteger('bank_account_id');
            $table->string('ref', 191);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'rail', 'bank_account_id'], 'pay_payees_unq');
        });

        $cell->table('pay_runs', function (Blueprint $table): void {
            $table->string('rail', 20)->default('bank_file');
        });
        $cell->table('pay_run_lines', function (Blueprint $table): void {
            $table->string('rail_ref', 191)->nullable();
            $table->string('rail_status', 10)->nullable(); // submitted, completed, failed, returned
            $table->string('failure_reason', 200)->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('pay_run_lines', fn (Blueprint $t) => $t->dropColumn(['rail_ref', 'rail_status', 'failure_reason', 'reversal_journal_id']));
        $cell->table('pay_runs', fn (Blueprint $t) => $t->dropColumn('rail'));
        $cell->dropIfExists('pay_payees');
        $cell->dropIfExists('pay_rail_settings');
        Schema::connection(Connections::control())->dropIfExists('pay_rail_refs');
        Schema::connection(Connections::control())->dropIfExists('pay_rail_platform');
    }
};
