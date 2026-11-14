<?php

declare(strict_types=1);

namespace Modules\Transactions\Risk;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reviews;

/**
 * The broker risk dashboard (specs/protect/01, first version, Sprint 15D): every open risk in the brokerage on
 * one list, each with a level, a category, an owner and a due date. Open Deal-IQ findings on open deals,
 * licences and E&O lapsed or lapsing within 30 days, and documents waiting past the review deadline. A weekly
 * trend, and a CSV export for E&O insurers and audits.
 */
final class RiskRegister
{
    public const CATEGORIES = ['checklist' => 'File completeness', 'docs' => 'Documents', 'agent' => 'Licensing', 'money' => 'Money', 'wire' => 'Wire fraud',
        'risk' => 'Deadlines', 'forms' => 'Forms', 'rule' => 'Brokerage rules', 'licensing' => 'Licensing', 'review' => 'Review'];

    /**
     * @return list<array{level: string, category: string, title: string, deal: ?string, street: ?string, owner: string, due: ?string, link: string}>
     */
    public static function all(): array
    {
        $db = DB::connection(Connections::cell());
        $names = fn (array $ids): array => User::query()->whereIn('id', array_unique($ids))->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name])->all();
        $risks = [];

        $findings = $db->table('tx_findings')->join('tx_deals', 'tx_deals.id', '=', 'tx_findings.deal_id')->where('tx_findings.status', 'open')
            ->whereIn('tx_findings.severity', ['blocker', 'warning'])->whereIn('tx_deals.status', ['pre_contract', 'under_contract'])
            ->get(['tx_findings.code', 'tx_findings.severity', 'tx_findings.message', 'tx_deals.ulid', 'tx_deals.street', 'tx_deals.agent_user_id', 'tx_deals.closing_date']);
        $people = $names($findings->pluck('agent_user_id')->map(fn ($v): int => (int) $v)->all());
        foreach ($findings as $f) {
            $prefix = explode('.', (string) $f->code)[0];
            $risks[] = ['level' => $f->severity === 'blocker' ? 'high' : 'medium', 'category' => self::CATEGORIES[$prefix] ?? 'Other', 'title' => (string) $f->message,
                'deal' => (string) $f->ulid, 'street' => (string) $f->street, 'owner' => $people[(int) $f->agent_user_id] ?? 'Unassigned',
                'due' => $f->closing_date !== null ? substr((string) $f->closing_date, 0, 10) : null, 'link' => '/deals/'.$f->ulid];
        }

        $soon = CarbonImmutable::today()->addDays(30)->toDateString();
        $creds = $db->table('roster_credentials')->whereIn('kind', ['licence', 'eo'])->where('status', 'current')->whereNotNull('expires_on')->where('expires_on', '<=', $soon)->get();
        $people = $names($creds->pluck('user_id')->map(fn ($v): int => (int) $v)->all());
        foreach ($creds as $c) {
            $expired = substr((string) $c->expires_on, 0, 10) < CarbonImmutable::today()->toDateString();
            $what = $c->kind === 'eo' ? 'E&O cover' : trim(($c->state_code ?? '').' licence');
            $user = User::query()->find((int) $c->user_id);
            if ($user === null || ! $user->isActive()) {
                continue;
            }
            $risks[] = ['level' => $expired ? 'high' : 'medium', 'category' => 'Licensing', 'title' => ($people[(int) $c->user_id] ?? 'Agent')."'s {$what} ".($expired ? 'expired' : 'expires').' '.CarbonImmutable::parse((string) $c->expires_on)->format('m/d/Y').'.',
                'deal' => null, 'street' => null, 'owner' => $people[(int) $c->user_id] ?? 'Agent', 'due' => substr((string) $c->expires_on, 0, 10), 'link' => '/roster/'.$user->ulid];
        }

        $reviews = app(Reviews::class);
        foreach (Item::query()->where('status', 'submitted')->get() as $item) {
            if ($reviews->overdue($item)) {
                $deal = $db->table('tx_deals')->where('id', $item->deal_id)->first(['ulid', 'street']);
                $risks[] = ['level' => 'medium', 'category' => 'Review', 'title' => "{$item->label} is waiting for review past the deadline.", 'deal' => $deal->ulid ?? null,
                    'street' => $deal->street ?? null, 'owner' => 'Reviewers', 'due' => null, 'link' => '/deals/review'];
            }
        }

        usort($risks, fn (array $a, array $b): int => [$a['level'] === 'high' ? 0 : 1, $a['due'] ?? '9999'] <=> [$b['level'] === 'high' ? 0 : 1, $b['due'] ?? '9999']);

        return $risks;
    }

    /**
     * Open findings at the end of each of the last weeks, and how many were opened and resolved that week.
     *
     * @return list<array{week: string, open: int, opened: int, resolved: int}>
     */
    public static function trend(int $weeks = 8): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $end = CarbonImmutable::today()->endOfWeek()->subWeeks($i);
            $start = $end->startOfWeek();
            $base = fn () => $db->table('tx_findings')->whereIn('severity', ['blocker', 'warning'])->where('status', '!=', 'dismissed');
            $out[] = ['week' => $start->toDateString(),
                'open' => $base()->where('first_seen_at', '<=', $end)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $end))->count(),
                'opened' => $base()->whereBetween('first_seen_at', [$start, $end])->count(),
                'resolved' => $base()->whereBetween('resolved_at', [$start, $end])->count()];
        }

        return $out;
    }

    public static function csv(): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['Level', 'Category', 'Risk', 'Property', 'Owner', 'Due'], ',', '"', '\\');
        foreach (self::all() as $r) {
            fputcsv($out, array_map(fn ($v): string => self::cell((string) $v), [$r['level'], $r['category'], $r['title'], $r['street'] ?? '', $r['owner'], $r['due'] ?? '']), ',', '"', '\\');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Spreadsheet-safe: values starting with =, +, - or @ are prefixed so they are never run as formulas. */
    private static function cell(string $v): string
    {
        return preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v;
    }
}
