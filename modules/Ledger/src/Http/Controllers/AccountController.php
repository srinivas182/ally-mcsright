<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ledger\Actions\ManageAccounts;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountController
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(): Response
    {
        Gate::authorize('finance.ledger.view');

        $accounts = LedgerAccount::query()->orderBy('code')->get()->map(fn (LedgerAccount $a): array => [
            'ulid' => $a->ulid,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type->value,
            'status' => $a->status,
            'is_system' => $a->is_system,
            'is_subledger' => $a->parent_id !== null,
            'balance' => $this->ledger->balance($a),
        ]);

        return Inertia::render('finance/accounts/index', [
            'accounts' => $accounts,
            'types' => array_map(fn (AccountType $t): string => $t->value, AccountType::cases()),
            'can' => ['adjust' => Gate::allows('finance.ledger.adjust')],
        ]);
    }

    public function store(Request $request, ManageAccounts $accounts): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:25'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(AccountType::class)],
        ]);

        $accounts->create($data['code'], $data['name'], AccountType::from($data['type']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account added.']);

        return back();
    }

    public function archive(LedgerAccount $account, ManageAccounts $accounts): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');

        $accounts->archive($account);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$account->code} archived."]);

        return back();
    }

    public function statement(Request $request, LedgerAccount $account): Response
    {
        Gate::authorize('finance.ledger.view');
        [$from, $to] = $this->range($request);
        $statement = $this->ledger->statement($account, $from, $to);

        return Inertia::render('finance/accounts/statement', [
            'account' => ['ulid' => $account->ulid, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening' => $statement->opening,
            'closing' => $statement->closing,
            'lines' => array_map(fn ($line): array => [
                'date' => $line->date->toDateString(),
                'journal' => $line->journalUlid,
                'type' => $line->journalType,
                'description' => $line->description,
                'memo' => $line->memo,
                'amount' => $line->amount,
                'balance' => $line->balance,
            ], $statement->lines),
            'can' => ['export' => Gate::allows('finance.ledger.export')],
        ]);
    }

    public function export(Request $request, LedgerAccount $account): StreamedResponse
    {
        Gate::authorize('finance.ledger.export');
        [$from, $to] = $this->range($request);
        $statement = $this->ledger->statement($account, $from, $to);
        $file = sprintf('statement-%s-%s-to-%s.csv', $account->code, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($statement, $account): void {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fputcsv($out, ['Account', $account->code.' '.$account->name], escape: '\\');
            fputcsv($out, ['Date', 'Journal', 'Type', 'Description', 'Memo', 'Amount', 'Balance'], escape: '\\');
            fputcsv($out, [$statement->from->toDateString(), '', 'opening', 'Opening balance', '', '', $statement->opening->toDecimal()], escape: '\\');
            foreach ($statement->lines as $line) {
                fputcsv($out, [
                    $line->date->toDateString(), $line->journalUlid, $line->journalType, self::safe($line->description),
                    self::safe($line->memo), $line->amount->toDecimal(), $line->balance->toDecimal(),
                ], escape: '\\');
            }
            fputcsv($out, [$statement->to->toDateString(), '', 'closing', 'Closing balance', '', '', $statement->closing->toDecimal()], escape: '\\');
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->startOfMonth();

        return [$from, $to];
    }

    /**
     * Neutralise spreadsheet formula injection in user-entered text.
     */
    private static function safe(?string $value): string
    {
        $value ??= '';

        return in_array(substr($value, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
