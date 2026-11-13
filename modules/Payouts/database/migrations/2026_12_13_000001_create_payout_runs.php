<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Payouts part 2 (specs/finance/02): payout runs and their lines, the brokerage's ACH originator and
 * operating-account details (for the bank file, checks and positive pay), and who pays each referral.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('pay_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('status', 10); // draft, approved, sent, cancelled
            $table->date('pay_on');
            $table->boolean('same_day')->default(false);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('pay_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->string('payee_type', 10); // agent, referral
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('referral_id')->nullable();
            $table->string('name', 160);
            $table->unsignedBigInteger('commission_cents')->default(0);
            $table->unsignedBigInteger('revenue_share_cents')->default(0);
            $table->unsignedBigInteger('referral_cents')->default(0);
            $table->unsignedBigInteger('amount_cents');
            $table->string('method', 6); // ach, check, none
            $table->string('status', 10); // included, skipped
            $table->string('skip_reason', 200)->nullable();
            $table->unsignedInteger('check_number')->nullable();
        });

        $schema->create('pay_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->string('company_name', 16)->nullable();     // ACH company name
            $table->string('company_id', 10)->nullable();       // ACH company identification (often 1 + EIN)
            $table->char('odfi_routing', 9)->nullable();        // the brokerage's bank routing number
            $table->string('bank_name', 23)->nullable();
            $table->text('operating_account_sealed')->nullable(); // for positive pay
            $table->unsignedInteger('next_check_number')->default(1001);
            $table->timestamps();
            $table->primary('tenant_id');
        });

        $schema->table('tx_referrals', function (Blueprint $table): void {
            $table->string('paid_by', 9)->default('title'); // title, brokerage
            $table->dateTime('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_referrals', fn (Blueprint $t) => $t->dropColumn(['paid_by', 'paid_at']));
        foreach (['pay_settings', 'pay_run_lines', 'pay_runs'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
