<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\Rules\Deduction;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The versioned fee catalogue (legacy rule 14). Plans reference fees by code; the version in force on
 * the closing date applies. Versions are never edited: a change is a new version from a date.
 */
final class FeeCatalogue
{
    /**
     * @param  array<string, mixed>  $definition  stage, calc, amount, charged_to, payable_to, counts_toward_cap, revenue_share_eligible, annual_cap_cents
     */
    public function publish(string $code, string $name, CarbonImmutable $effectiveFrom, array $definition, ?User $by = null): int
    {
        $errors = [];
        $parsed = Deduction::parseList([['code' => $code, 'name' => $name] + $definition], 'fee', $errors);
        if (! in_array($definition['stage'] ?? '', ['pre', 'post'], true)) {
            $errors['fee.stage'] = 'Choose before the split (pre) or after it (post).';
        }
        if ($errors !== [] || $parsed === []) {
            throw new InvalidRules($errors);
        }
        $d = $parsed[0];
        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $code, $name, $effectiveFrom, $definition, $d, $by): int {
            $tenant = app(TenantContext::class)->id();
            $feeId = (int) ($db->table('comp_fees')->where('tenant_id', $tenant)->where('code', $code)->value('id')
                ?? $db->table('comp_fees')->insertGetId(['tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]));
            $db->table('comp_fees')->where('id', $feeId)->update(['name' => $name, 'updated_at' => now()]);
            $version = (int) $db->table('comp_fee_versions')->where('fee_id', $feeId)->max('version') + 1;
            $db->table('comp_fee_versions')->insert([
                'tenant_id' => $tenant, 'fee_id' => $feeId, 'version' => $version, 'effective_from' => $effectiveFrom->toDateString(),
                'stage' => $definition['stage'], 'calc' => $d->calc, 'amount' => $d->amount, 'charged_to' => $d->chargedTo, 'payable_to' => $d->payableTo,
                'counts_toward_cap' => $d->countsTowardCap, 'revenue_share_eligible' => $d->revenueShareEligible, 'annual_cap_cents' => $d->annualCapCents,
                'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record('compensation.fee.published', metadata: ['code' => $code, 'version' => $version, 'from' => $effectiveFrom->toDateString()] + $d->toArray(), severity: 'notice', label: $name);

            return $version;
        });
    }

    /**
     * Catalogue fees in force on a date, as [pre-split, post-split] deductions.
     *
     * @param  list<string>  $codes
     * @return array{0: list<Deduction>, 1: list<Deduction>}
     */
    public function resolve(array $codes, CarbonImmutable $date): array
    {
        if ($codes === []) {
            return [[], []];
        }
        $db = DB::connection(Connections::cell());
        $pre = [];
        $post = [];
        $missing = [];
        foreach ($codes as $code) {
            $fee = $db->table('comp_fees')->where('tenant_id', app(TenantContext::class)->id())->where('code', $code)->first();
            $v = $fee !== null ? $db->table('comp_fee_versions')->where('fee_id', $fee->id)->whereDate('effective_from', '<=', $date->toDateString())
                ->orderByDesc('effective_from')->orderByDesc('version')->first() : null;
            if ($fee === null || $v === null) {
                $missing["fees.{$code}"] = "Fee {$code} is not in the catalogue on {$date->toDateString()}.";

                continue;
            }
            $d = new Deduction($code, (string) $fee->name, (string) $v->calc, (string) $v->amount, (string) $v->payable_to, (string) $v->charged_to,
                (bool) $v->counts_toward_cap, (bool) $v->revenue_share_eligible, $v->annual_cap_cents !== null ? (int) $v->annual_cap_cents : null);
            $v->stage === 'pre' ? $pre[] = $d : $post[] = $d;
        }
        if ($missing !== []) {
            throw new InvalidRules($missing);
        }

        return [$pre, $post];
    }
}
