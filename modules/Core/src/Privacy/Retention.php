<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * How long each kind of data is kept (TR1, specs/core/18). The owner chooses a number of months (never below the
 * minimum for that kind) or "keep"; every night data older than that is removed. Transaction files, money records and
 * the audit log are never removed here: they have their own rules (audit log retention, 7–25 years).
 */
final class Retention
{
    public function __construct(private readonly TenantContext $tenants, private readonly PrivacyRegistry $registry) {}

    /** @return list<array{kind: string, label: string, about: string, months: int|null, default: int|null, min: int, last_run_at: string|null, last_removed: int}> */
    public function rules(): array
    {
        $rows = DB::connection(Connections::cell())->table('retention_rules')->where('tenant_id', $this->tenants->id())->get()->keyBy('kind');
        $out = [];
        foreach ($this->registry->retentionKinds() as $kind => $def) {
            $r = $rows->get($kind);
            $out[] = ['kind' => $kind, 'label' => $def['label'], 'about' => $def['about'], 'months' => $r !== null ? ($r->months !== null ? (int) $r->months : null) : $def['default'],
                'default' => $def['default'], 'min' => $def['min'], 'last_run_at' => $r->last_run_at ?? null, 'last_removed' => (int) ($r->last_removed ?? 0)];
        }

        return $out;
    }

    public function set(string $kind, ?int $months, User $by): void
    {
        $def = $this->registry->retentionKinds()[$kind] ?? throw ValidationException::withMessages(['kind' => 'Unknown kind of data.']);
        if ($months !== null && ($months < $def['min'] || $months > 120)) {
            throw ValidationException::withMessages(['months' => 'Choose from '.$def['min'].' to 120 months, or keep.']);
        }
        DB::connection(Connections::cell())->table('retention_rules')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'kind' => $kind],
            ['months' => $months, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('core.retention.set', metadata: ['kind' => $kind, 'months' => $months], severity: 'warning');
    }

    /** Removes data past its time in the current brokerage. Returns how many records were removed. */
    public function run(): int
    {
        $total = 0;
        foreach ($this->rules() as $rule) {
            if ($rule['months'] === null) {
                continue;
            }
            $removed = ($this->registry->retentionKinds()[$rule['kind']]['purge'])(CarbonImmutable::now()->subMonths($rule['months']));
            DB::connection(Connections::cell())->table('retention_rules')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'kind' => $rule['kind']],
                ['months' => $rule['months'], 'last_run_at' => now(), 'last_removed' => $removed, 'updated_at' => now()]);
            $total += $removed;
        }

        return $total;
    }
}
