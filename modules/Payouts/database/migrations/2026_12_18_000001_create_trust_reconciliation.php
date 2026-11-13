<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Three-way trust reconciliation (specs/transactions/01, addition 3): bank statements, lines and matches. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('trust_statements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->char('month', 7);
            $table->bigInteger('ending_balance_cents');
            $table->char('file_sha256', 64);
            $table->string('status', 10); // draft, approved
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->json('snapshot')->nullable(); // the three balances at approval
            $table->timestamps();
            $table->unique(['tenant_id', 'month'], 'trust_statements_month_unq');
        });

        $cell->create('trust_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('statement_id')->constrained('trust_statements')->cascadeOnDelete();
            $table->date('posted_on');
            $table->string('description', 200);
            $table->bigInteger('amount_cents'); // + into the trust account, − out
        });

        $cell->create('trust_matches', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('ledger_line_id');
            $table->foreignId('statement_line_id')->constrained('trust_statement_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('matched_by')->nullable(); // null: automatic
            $table->dateTime('created_at');
            $table->primary(['tenant_id', 'ledger_line_id']);
            $table->unique('statement_line_id');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['trust_matches', 'trust_statement_lines', 'trust_statements'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
