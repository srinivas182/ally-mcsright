<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * FH1 (specs/finance/22): manual adjustments wait for a second person, months close against a checklist,
 * and every close keeps a copy of the statements exactly as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ledger_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('description', 255);
            $table->date('effective_date');
            $table->json('lines');
            $table->unsignedBigInteger('total_cents');
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 10)->default('waiting'); // waiting, approved, declined, withdrawn
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->boolean('self_approved')->default(false);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'ledger_adj_req_key');
            $table->index(['tenant_id', 'status', 'effective_date'], 'ledger_adj_req_status');
        });

        $cell->table('ledger_periods', function (Blueprint $table): void {
            $table->json('close_notes')->nullable();
            $table->dateTime('checked_at')->nullable();
        });

        $cell->create('ledger_close_findings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('period_id');
            $table->string('check_key', 40);
            $table->string('ref', 60)->default('');
            $table->string('level', 5); // stop, check
            $table->string('title', 160);
            $table->string('detail', 500);
            $table->string('link', 191)->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->unique(['tenant_id', 'period_id', 'check_key', 'ref'], 'ledger_find_unique');
            $table->index(['tenant_id', 'period_id', 'resolved_at'], 'ledger_find_open');
        });

        $cell->create('ledger_period_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('period_id');
            $table->string('kind', 5); // month, year
            $table->longText('payload');
            $table->char('sha256', 64);
            $table->dateTime('created_at');
            $table->unique(['tenant_id', 'period_id', 'kind'], 'ledger_snap_unique');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ledger_period_snapshots');
        $cell->dropIfExists('ledger_close_findings');
        $cell->table('ledger_periods', function (Blueprint $table): void {
            $table->dropColumn(['close_notes', 'checked_at']);
        });
        $cell->dropIfExists('ledger_adjustment_requests');
    }
};
