<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Fields;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Forecast;
use Modules\Transactions\Mls\MlsListings;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Reports\ListingBoard;
use Modules\Transactions\Reports\Royalties;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** LM1 (specs/transactions/30): the listing inventory board, and the money page (cash forecast, franchise and royalty report). */
final class BoardController
{
    public function listings(Request $request, ListingBoard $board, MlsListings $mls): Response
    {
        $user = self::user($request);
        $mine = ! DealAccess::reviewer($user) || $request->query('mine') === '1';
        $agent = $mine ? (User::query()->find(DealAccess::actingFor($user)) ?? $user) : null;

        return Inertia::render('deals/listings', ['board' => $board->build($agent), 'columns' => ListingBoard::COLUMNS, 'mine' => $mine, 'reviewer' => DealAccess::reviewer($user),
            // PG3: "Add a listing" on the board (a reviewer picks the agent; an agent adds their own).
            'canCreateFor' => DealAccess::reviewer($user) ? User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values()->all() : [],
            // ML2: the agents' own listings found in the MLS, and deals whose MLS status moved on.
            'mls' => $mls->board($agent), 'isOwner' => $user->isKind(UserKind::Owner)]);
    }

    /** ML2: add a listing found in the MLS to My listings. */
    public function addFromMls(Request $request, string $listing, MlsListings $mls): RedirectResponse
    {
        $deal = $mls->add($listing, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$deal->street} to My listings."]);

        return back();
    }

    /** ML2: "not mine to add": not offered again. */
    public function dismissFromMls(Request $request, string $listing, MlsListings $mls): RedirectResponse
    {
        $mls->dismiss($listing, self::user($request));

        return back();
    }

    /** ML2: the owner lets listings found in the MLS be added by themselves. */
    public function mlsAuto(Request $request, MlsListings $mls): RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $mls->setAutoAdd((bool) $data['on'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['on'] ? 'New MLS listings will be added by themselves every night.' : 'New MLS listings will wait for you to add them.']);

        return back();
    }

    /** ML2: homes your agents sold in the MLS that have no deal in RightAlly. */
    public function mlsCheck(Request $request, MlsListings $mls): Response
    {
        abort_unless(DealAccess::reviewer(self::user($request)), 403);

        return Inertia::render('deals/mls-check', ['sales' => $mls->missingSales(), 'days' => MlsListings::REPORT_DAYS]);
    }

    /** The listing agreement's end date and the MLS number, from the board. */
    public function saveListing(Request $request, Deal $deal): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal) && in_array($deal->type, ['listing', 'dual'], true), 403);
        $data = $request->validate(['listing_expires_on' => ['nullable', 'date_format:Y-m-d'], 'mls_number' => ['nullable', 'string', 'max:30']]);
        $deal->forceFill(['listing_expires_on' => $data['listing_expires_on'] ?? null, 'mls_number' => ($data['mls_number'] ?? '') !== '' ? $data['mls_number'] : null])->save();
        app(AuditLogger::class)->record('transactions.listing_updated', $deal, metadata: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Listing saved.']);

        return back();
    }

    public function money(Request $request, Forecast $forecast, Royalties $royalties): Response
    {
        self::moneyReader($request);
        [$from, $to] = self::period($request);

        return Inertia::render('deals/money', ['pending' => $forecast->pending(), 'weekly' => $forecast->weekly(), 'royalties' => $royalties->between($from, $to),
            'month' => $from->format('Y-m')]);
    }

    public function forecastCsv(Request $request, Forecast $forecast): StreamedResponse
    {
        self::moneyReader($request);
        $rows = [['Week of', 'Closing date', 'Property', 'Agent', 'GCI', 'Company dollar', 'At risk']];
        foreach ($forecast->weekly() as $week) {
            foreach ($week['deals'] as $d) {
                $rows[] = [$week['week_of'], $d['closing_date'], $d['street'], $d['agent'], self::dollars($d['gci_cents']), self::dollars($d['company_cents']), $d['at_risk'] ? 'Yes' : ''];
            }
        }

        return self::csv('cash-forecast-'.CarbonImmutable::today()->toDateString().'.csv', $rows);
    }

    public function royaltiesCsv(Request $request, Royalties $royalties): StreamedResponse
    {
        self::moneyReader($request);
        [$from, $to] = self::period($request);
        $r = $royalties->between($from, $to);
        $rows = [['Closed', 'Property', 'Agent', 'GCI', 'Fee', 'Code', 'Amount']];
        foreach ($r['rows'] as $row) {
            $rows[] = [$row['closed_on'], $row['street'], $row['agent'], self::dollars($row['gci_cents']), $row['fee'], $row['code'], self::dollars($row['cents'])];
        }
        $rows[] = ['Total', '', '', self::dollars($r['gci_cents']), '', '', self::dollars($r['total_cents'])];
        app(AuditLogger::class)->record('transactions.royalty_report_exported', metadata: ['from' => $r['from'], 'to' => $r['to']], severity: 'notice');

        return self::csv('royalties-'.$from->format('Y-m').'.csv', $rows);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function period(Request $request): array
    {
        $month = (string) $request->query('month', '');
        try {
            $from = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $month) : null;
        } catch (\Throwable) {
            $from = null;
        }
        $from = $from instanceof CarbonImmutable ? $from->startOfMonth() : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        return [$from, $from->endOfMonth()];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private static function csv(string $name, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach ($rows as $row) {
                // Spreadsheet safety: never let a cell start a formula.
                fputcsv($out, array_map(fn (string $c): string => preg_match('/^[=+\-@\t\r]/', $c) === 1 ? "'".$c : $c, $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private static function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function moneyReader(Request $request): User
    {
        $user = self::user($request);
        abort_unless(DealAccess::reviewer($user) && Fields::visible($user, 'commission'), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
