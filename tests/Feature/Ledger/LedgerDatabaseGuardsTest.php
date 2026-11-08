<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

/*
| Database-level immutability (MySQL triggers). These bypass the application models entirely,
| proving the database itself refuses to change ledger history. Skipped on SQLite.
*/

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL-only: database triggers.');
    }

    app(TenantContext::class)->set(Tenant::factory()->create());
    $chart = app(ChartOfAccounts::class);
    $chart->seed();
    $this->journal = app(LedgerService::class)->post(
        JournalDraft::make('test', 'guard-1')
            ->debit($chart->account('1000'), Money::of(5_000))
            ->credit($chart->account('3000'), Money::of(5_000)),
    );
});

it('refuses raw updates and deletes of ledger lines', function (): void {
    expect(fn () => DB::table('ledger_lines')->update(['debit_cents' => 1]))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('ledger_lines')->delete())->toThrow(QueryException::class, 'append-only');
});

it('refuses raw deletes of journals and edits other than one reversal link', function (): void {
    expect(fn () => DB::table('ledger_journals')->delete())->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('ledger_journals')->update(['effective_date' => '2020-01-01']))->toThrow(QueryException::class, 'immutable');

    DB::table('ledger_journals')->where('id', $this->journal->id)->update(['reversed_by_id' => 999]);

    expect(fn () => DB::table('ledger_journals')->where('id', $this->journal->id)->update(['reversed_by_id' => 1000]))
        ->toThrow(QueryException::class, 'immutable');
});
