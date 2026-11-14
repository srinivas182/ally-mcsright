<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * A deal's key dates (inspection, financing, appraisal, closing…): added by hand or from the forms' plain-English
 * summary, ticked off when done, with reminder emails to the agent (transactions:date-reminders). Deal-IQ's
 * escalation builds on these.
 */
final class KeyDates
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<array{id: int, label: string, due_on: string, done: bool, source: string, overdue: bool, soon: bool}>
     */
    public function forDeal(Deal $deal): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $soon = CarbonImmutable::today()->addDays(3)->toDateString();

        return array_values(DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->orderBy('due_on')->orderBy('id')->get()
            ->map(fn ($d): array => ['id' => (int) $d->id, 'label' => (string) $d->label, 'due_on' => substr((string) $d->due_on, 0, 10), 'done' => $d->done_at !== null,
                'source' => (string) $d->source, 'overdue' => $d->done_at === null && substr((string) $d->due_on, 0, 10) < $today,
                'soon' => $d->done_at === null && substr((string) $d->due_on, 0, 10) >= $today && substr((string) $d->due_on, 0, 10) <= $soon])->all());
    }

    public function add(Deal $deal, string $label, string $dueOn): void
    {
        $label = mb_substr(trim($label), 0, 80);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dueOn);
        } catch (\Throwable) {
            $date = null;
        }
        if ($label === '' || $date === null) {
            throw ValidationException::withMessages(['date' => 'Give the date a name and a day.']);
        }
        DB::connection(Connections::cell())->table('tx_dates')->insert(['tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id, 'label' => $label,
            'due_on' => $date->toDateString(), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function toggle(Deal $deal, int $id): void
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_dates')->where('deal_id', $deal->id)->where('id', $id)->first() ?? abort(404);
        $db->table('tx_dates')->where('id', $row->id)->update(['done_at' => $row->done_at === null ? now() : null, 'updated_at' => now()]);
    }

    public function remove(Deal $deal, int $id): void
    {
        DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->where('id', $id)->delete();
    }
}
