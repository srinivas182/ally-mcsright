<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Agent billing (specs/finance/02, part 4): fee items, agents' recurring fees, invoices, payment plans,
 * and how each invoice was paid (deducted from commission or paid online to the brokerage).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('bill_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 80);
            $table->unsignedBigInteger('amount_cents');
            $table->string('frequency', 8); // monthly, yearly, once
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $cell->create('bill_assignments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('item_id')->constrained('bill_items');
            $table->unsignedBigInteger('amount_cents');
            $table->date('next_bill_on');
            $table->date('ended_on')->nullable();
            $table->timestamps();
        });

        $cell->create('bill_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedSmallInteger('instalments');
            $table->string('reason', 200);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
        });

        $cell->create('bill_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('number', 20);
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('status', 10); // open, paid, void
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->boolean('late_fee_applied')->default(false);
            $table->date('reminded_on')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->boolean('posts_revenue')->default(true); // payment-plan instalments move a debt; they are not new revenue
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status'], 'bill_invoices_user_idx');
        });

        $cell->create('bill_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('invoice_id')->constrained('bill_invoices')->cascadeOnDelete();
            $table->string('description', 160);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('item_id')->nullable();
        });

        $cell->create('bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('invoice_id')->constrained('bill_invoices');
            $table->unsignedBigInteger('amount_cents');
            $table->string('method', 12); // commission, online
            $table->unsignedBigInteger('run_line_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->dateTime('paid_at');
        });

        $cell->create('bill_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->boolean('deduct_from_commission')->default(true);
            $table->unsignedInteger('late_fee_cents')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(5);
            $table->unsignedSmallInteger('due_days')->default(15);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary('tenant_id');
        });

        $cell->table('pay_run_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('deduction_cents')->default(0);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('pay_run_lines', fn (Blueprint $t) => $t->dropColumn('deduction_cents'));
        foreach (['bill_settings', 'bill_payments', 'bill_invoice_lines', 'bill_invoices', 'bill_plans', 'bill_assignments', 'bill_items'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
