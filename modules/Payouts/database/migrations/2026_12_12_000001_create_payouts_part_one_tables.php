<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Payouts part 1 (specs/finance/02): money received from title matched to the CDA, and agents'
 * protected bank details.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('pay_receipts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('expected_cents');
            $table->date('received_on');
            $table->string('method', 12); // wire, ach, check
            $table->string('reference', 60)->nullable();
            $table->string('status', 10); // matched, short, over, accepted
            $table->string('resolution', 300)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
        });

        $schema->create('pay_recognitions', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('journal_id');
            $table->json('payables'); // user id => cents
            $table->dateTime('created_at');
            $table->primary(['tenant_id', 'deal_id']);
        });

        $schema->create('pay_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('holder_name', 120);
            $table->text('routing_sealed');
            $table->text('account_sealed');
            $table->char('last4', 4);
            $table->string('type', 8); // checking, savings
            $table->string('status', 10); // active, replaced
            $table->dateTime('usable_from');
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status'], 'pay_bank_user_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['pay_bank_accounts', 'pay_recognitions', 'pay_receipts'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
