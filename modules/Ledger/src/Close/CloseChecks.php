<?php

declare(strict_types=1);

namespace Modules\Ledger\Close;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Models\LedgerPeriod;

/**
 * The month-end checklist (specs/finance/22). Every registered check runs for a month; what it finds is
 * kept (first seen, last seen, resolved) so the nightly run can tell owners only about new problems.
 */
final class CloseChecks
{
    /** @var array<string, class-string<CloseCheck>> */
    private array $checks = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  class-string<CloseCheck>  $check */
    public function register(string $check): void
    {
        $this->checks[$check] = $check;
    }

    /** @return list<CloseCheck> */
    public function all(): array
    {
        return array_values(array_map(fn (string $c): CloseCheck => app($c), $this->checks));
    }

    /**
     * Runs every check for the month and stores the result.
     *
     * @return array{checks: list<array{key: string, label: string, ok: bool}>, findings: list<array{key: string, ref: string, level: string, title: string, detail: string, link: string|null, new: bool}>}
     */
    public function refresh(LedgerPeriod $period): array
    {
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $now = now();
        $checks = [];
        $found = [];
        foreach ($this->all() as $check) {
            $findings = $check->run($period);
            $checks[] = ['key' => $check->key(), 'label' => $check->label(), 'ok' => $findings === []];
            foreach ($findings as $f) {
                $ref = $f->ref !== '' ? mb_substr($f->ref, 0, 60) : substr(sha1($f->title), 0, 12);
                $found[$check->key().'|'.$ref] = ['key' => $check->key(), 'ref' => $ref, 'level' => $f->level, 'title' => mb_substr($f->title, 0, 160),
                    'detail' => mb_substr($f->detail, 0, 500), 'link' => $f->link];
            }
        }

        $existing = $db->table('ledger_close_findings')->where('tenant_id', $tenant)->where('period_id', $period->id)->whereNull('resolved_at')->get()
            ->keyBy(fn ($r): string => $r->check_key.'|'.$r->ref);
        $out = [];
        foreach ($found as $k => $f) {
            $row = ['level' => $f['level'], 'title' => $f['title'], 'detail' => $f['detail'], 'link' => $f['link'], 'last_seen_at' => $now];
            $current = $existing->get($k);
            if ($current !== null) {
                $db->table('ledger_close_findings')->where('id', $current->id)->update($row);
            } else {
                // A finding fixed earlier and back again is reopened rather than duplicated.
                $db->table('ledger_close_findings')->updateOrInsert(
                    ['tenant_id' => $tenant, 'period_id' => $period->id, 'check_key' => $f['key'], 'ref' => $f['ref']],
                    $row + ['first_seen_at' => $now, 'resolved_at' => null, 'notified_at' => null],
                );
            }
            $out[] = $f + ['new' => ! $existing->has($k)];
        }
        foreach ($existing as $k => $row) {
            if (! isset($found[$k])) {
                $db->table('ledger_close_findings')->where('id', $row->id)->update(['resolved_at' => $now]);
            }
        }
        $period->forceFill(['checked_at' => $now])->save();
        usort($out, fn (array $a, array $b): int => [$a['level'] === Finding::STOP ? 0 : 1, $a['title']] <=> [$b['level'] === Finding::STOP ? 0 : 1, $b['title']]);

        return ['checks' => $checks, 'findings' => $out];
    }

    /**
     * What was found last time, without running the checks again.
     *
     * @return list<array{key: string, ref: string, level: string, title: string, detail: string, link: string|null}>
     */
    public function open(LedgerPeriod $period): array
    {
        return array_values(DB::connection(Connections::cell())->table('ledger_close_findings')->where('tenant_id', $this->tenants->id())
            ->where('period_id', $period->id)->whereNull('resolved_at')->orderByRaw("case when level = 'stop' then 0 else 1 end")->orderBy('title')->get()
            ->map(fn ($r): array => ['key' => (string) $r->check_key, 'ref' => (string) $r->ref, 'level' => (string) $r->level, 'title' => (string) $r->title,
                'detail' => (string) $r->detail, 'link' => $r->link !== null ? (string) $r->link : null])->all());
    }

    /**
     * Marks stop findings as told to the owners and returns the ones not told before.
     *
     * @return list<string> titles
     */
    public function claimUnnotifiedStops(LedgerPeriod $period): array
    {
        $db = DB::connection(Connections::cell());
        $rows = $db->table('ledger_close_findings')->where('tenant_id', $this->tenants->id())->where('period_id', $period->id)
            ->whereNull('resolved_at')->whereNull('notified_at')->where('level', Finding::STOP)->get(['id', 'title']);
        if ($rows->isNotEmpty()) {
            $db->table('ledger_close_findings')->whereIn('id', $rows->pluck('id')->all())->update(['notified_at' => now()]);
        }

        return array_values($rows->pluck('title')->map(fn ($t): string => (string) $t)->all());
    }
}
