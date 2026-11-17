<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AC1 (specs/finance/20): bills to pay, invoices to collect, and the operating bank statement with its reconciliation. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('acct_bills', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('vendor', 160);
            $table->string('bill_number', 60)->nullable();
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('account_code', 25);
            $table->unsignedBigInteger('amount_cents');
            $table->string('memo', 500)->nullable();
            $table->string('status', 8)->default('open'); // open, paid, void
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_ref', 60)->nullable();
            $table->unsignedBigInteger('paid_journal_id')->nullable();
            $table->string('file_path', 300)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_date']);
        });
        $cell->create('acct_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedInteger('number');
            $table->string('customer', 160);
            $table->string('customer_email', 150)->nullable();
            $table->string('customer_address', 300)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->json('lines');
            $table->unsignedBigInteger('total_cents');
            $table->string('account_code', 25)->default('4200');
            $table->string('memo', 500)->nullable();
            $table->string('status', 8)->default('open'); // open, paid, void
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_ref', 60)->nullable();
            $table->unsignedBigInteger('paid_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });
        $cell->create('acct_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->char('month', 7);
            $table->bigInteger('ending_balance_cents');
            $table->string('file_sha256', 64);
            $table->string('status', 10)->default('draft'); // draft, approved
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'month']);
        });
        $cell->create('acct_bank_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('statement_id');
            $table->date('posted_on');
            $table->string('description', 300);
            $table->bigInteger('amount_cents'); // + money in, − money out
            $table->string('fitid', 80)->nullable();
            $table->unsignedBigInteger('ledger_line_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable(); // when added to the books from here
            $table->timestamps();
            $table->index(['statement_id']);
            $table->index(['tenant_id', 'ledger_line_id']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['acct_bank_lines', 'acct_bank_statements', 'acct_invoices', 'acct_bills'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
