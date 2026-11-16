<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Saved, shared and scheduled reports (specs/insights/01, K2). A shared report is the same question for everyone:
 * each person who opens it sees only what they may see.
 */
final class SavedReports
{
    /**
     * @return list<array{ulid: string, name: string, subject: string, shared: bool, schedule: string|null, mine: bool, definition: array<mixed>}>
     */
    public function visibleTo(User $user): array
    {
        return array_values(DB::connection(Connections::cell())->table('insights_reports')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhere('shared', true))->orderBy('name')->get()
            ->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'name' => (string) $r->name, 'subject' => (string) (json_decode((string) $r->definition, true)['subject'] ?? ''),
                'shared' => (bool) $r->shared, 'schedule' => $r->schedule !== null ? (string) $r->schedule : null, 'mine' => (int) $r->owner_user_id === $user->id,
                'definition' => (array) json_decode((string) $r->definition, true)])->all());
    }

    /**
     * @param  array<mixed>  $definition
     */
    public function save(User $by, ?string $ulid, string $name, array $definition, bool $shared, ?string $schedule): string
    {
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Give the report a name.']);
        }
        if ($schedule !== null && ! in_array($schedule, ['weekly', 'monthly'], true)) {
            throw ValidationException::withMessages(['schedule' => 'Choose weekly or monthly.']);
        }
        app(Reports::class)->run($by, $definition); // checks it is a report this person can run
        $db = DB::connection(Connections::cell());
        $row = ['name' => mb_substr(trim($name), 0, 120), 'definition' => json_encode(array_intersect_key($definition, array_flip(['subject', 'columns', 'filters', 'group_by']))),
            'shared' => $shared, 'schedule' => $schedule, 'updated_at' => CarbonImmutable::now()];
        if ($ulid !== null) {
            $db->table('insights_reports')->where('ulid', $ulid)->where('owner_user_id', $by->id)->whereNull('deleted_at')->update($row) || abort(404);
        } else {
            $ulid = (string) Str::ulid();
            $db->table('insights_reports')->insert($row + ['ulid' => $ulid, 'tenant_id' => $by->tenant_id, 'owner_user_id' => $by->id, 'created_at' => CarbonImmutable::now()]);
        }
        app(AuditLogger::class)->record('insights.report.saved', $by, metadata: ['report' => $ulid, 'shared' => $shared, 'schedule' => $schedule], label: $name);

        return $ulid;
    }

    public function delete(User $by, string $ulid): void
    {
        DB::connection(Connections::cell())->table('insights_reports')->where('ulid', $ulid)->where('owner_user_id', $by->id)->update(['deleted_at' => CarbonImmutable::now()]);
    }

    /** Emails reports that are due: weekly on Mondays, monthly on the 1st, to their owner, as their owner sees them. */
    public function sendDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $due = array_filter([$today->isMonday() ? 'weekly' : null, $today->day === 1 ? 'monthly' : null]);
        if ($due === []) {
            return 0;
        }
        $db = DB::connection(Connections::cell());
        $sent = 0;
        foreach ($db->table('insights_reports')->whereNull('deleted_at')->whereIn('schedule', $due)
            ->where(fn ($q) => $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<', $today->startOfDay()))->get() as $r) {
            $owner = User::query()->find($r->owner_user_id);
            if (! $owner instanceof User || ! $owner->isActive()) {
                continue;
            }
            $result = app(Reports::class)->run($owner, (array) json_decode((string) $r->definition, true));
            $owner->notify(new ReportMail((string) $r->name, $result, (string) $r->ulid));
            $db->table('insights_reports')->where('id', $r->id)->update(['last_sent_at' => CarbonImmutable::now()]);
            $sent++;
        }

        return $sent;
    }

    /**
     * The report as a CSV (spreadsheet formulas neutralised); money in dollars.
     *
     * @param  array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>}  $result
     */
    public static function csv(array $result): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, array_column($result['columns'], 'label'));
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(function (array $c) use ($row): string {
                $v = $row[$c['key']] ?? '';
                $v = $c['type'] === 'money' && is_numeric($v) ? number_format((int) $v / 100, 2, '.', '') : (is_scalar($v) ? (string) $v : '');

                return preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v;
            }, $result['columns']));
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
