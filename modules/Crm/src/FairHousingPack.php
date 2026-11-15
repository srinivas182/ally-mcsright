<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The monthly Fair Housing audit pack (specs/grow/12): for each channel, how many messages the check looked at and
 * how many it stopped, removed or warned about, then every flagged item with the wording, the reason and who was
 * writing. For the broker's records, the MLS or a regulator.
 */
final class FairHousingPack
{
    /** @return array<string, mixed> */
    public function build(string $month): array
    {
        $from = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $to = $from->endOfMonth();
        $tenant = app(TenantContext::class)->id();
        $db = DB::connection(Connections::cell());
        $counts = $db->table('crm_fair_housing_checks')->where('tenant_id', $tenant)->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('channel, sum(checked) as checked, sum(flagged) as flagged')->groupBy('channel')->get()->keyBy('channel');
        $log = $db->table('crm_fair_housing_log')->where('tenant_id', $tenant)->whereBetween('created_at', [$from, $to->endOfDay()])->orderBy('created_at')->limit(2000)->get();
        $names = User::query()->whereIn('id', $log->pluck('user_id')->filter()->unique()->all())->get()->keyBy('id');
        $channels = [];
        foreach (FairHousing::CHANNELS as $key => $label) {
            $rows = $log->where('channel', $key);
            $channels[] = ['key' => $key, 'label' => $label, 'checked' => (int) ($counts->get($key)->checked ?? 0), 'flagged' => (int) ($counts->get($key)->flagged ?? 0),
                'blocked' => $rows->where('action', 'blocked')->count(), 'removed' => $rows->where('action', 'removed')->count(), 'warned' => $rows->where('action', 'warned')->count()];
        }

        return [
            'month' => $from->format('Y-m'), 'label' => $from->format('F Y'), 'brokerage' => (string) app(TenantContext::class)->current()->name,
            'channels' => $channels, 'checked' => array_sum(array_column($channels, 'checked')), 'flagged' => array_sum(array_column($channels, 'flagged')),
            'items' => $log->map(fn (object $r): array => ['at' => (string) $r->created_at, 'channel' => FairHousing::CHANNELS[$r->channel] ?? $r->channel, 'action' => (string) $r->action,
                'phrases' => array_values((array) json_decode((string) $r->phrases, true)), 'excerpt' => (string) $r->excerpt, 'where' => $r->where,
                'by' => $r->user_id !== null ? ($names->get((int) $r->user_id)->name ?? 'A former user') : 'Automatic (AI)'])->values()->all(),
            'phrases' => FairHousing::FLAGS,
        ];
    }

    /** @return list<array{month: string, label: string, checked: int, flagged: int}> the last 12 months, newest first */
    public function months(): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        for ($m = CarbonImmutable::now()->startOfMonth(), $i = 0; $i < 12; $m = $m->subMonth(), $i++) {
            $row = $db->table('crm_fair_housing_checks')->where('tenant_id', app(TenantContext::class)->id())->whereBetween('day', [$m->toDateString(), $m->endOfMonth()->toDateString()])
                ->selectRaw('sum(checked) as checked, sum(flagged) as flagged')->first();
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('F Y'), 'checked' => (int) ($row->checked ?? 0), 'flagged' => (int) ($row->flagged ?? 0)];
        }

        return $out;
    }
}
