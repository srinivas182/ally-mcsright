<?php

declare(strict_types=1);

namespace Modules\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\AgentPlan;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * What HQ sells (specs/core/07): agent plans with a public list price and a brokerage floor price,
 * commission tiers, and brokerage packages. Increases to list or floor prices reach brokerages after
 * 60 days' notice; decreases apply at once.
 */
final class Catalogue
{
    public const BROKERAGE_NOTICE_DAYS = 60;

    /**
     * @param  array{key: string, name: string, description?: string|null, list_cents: int, floor_cents: int, features: list<string>, sort?: int, active?: bool}  $data
     */
    public function savePlan(?AgentPlan $plan, array $data): AgentPlan
    {
        $unknown = array_diff($data['features'], array_keys(ProductFeatures::all()));
        if ($data['floor_cents'] > $data['list_cents'] || $data['list_cents'] <= 0) {
            throw ValidationException::withMessages(['floor_cents' => 'The brokerage floor price must be at or below the public list price.']);
        }
        if ($unknown !== []) {
            throw ValidationException::withMessages(['features' => 'Unknown feature: '.implode(', ', $unknown)]);
        }

        return DB::connection(Connections::control())->transaction(function () use ($plan, $data): AgentPlan {
            $now = CarbonImmutable::now();
            if ($plan !== null) {
                foreach (['list' => 'list_cents', 'floor' => 'floor_cents'] as $field => $column) {
                    $old = (int) $plan->getAttribute($column);
                    $new = (int) $data[$column];
                    if ($new > $old) {
                        // Increase: brokerages keep the old price for 60 days.
                        DB::connection(Connections::control())->table('billing_price_notices')->insert([
                            'agent_plan_id' => $plan->id, 'field' => $field, 'old_cents' => $old, 'new_cents' => $new,
                            'effective_at' => $now->addDays(self::BROKERAGE_NOTICE_DAYS), 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
            $plan ??= new AgentPlan;
            $plan->fill([
                'key' => $data['key'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'list_cents' => $data['list_cents'], 'floor_cents' => $data['floor_cents'], 'features' => array_values(array_unique([...ProductFeatures::MINIMAL, ...$data['features']])),
                'sort' => $data['sort'] ?? 0, 'active' => $data['active'] ?? true,
            ])->save();
            app(AuditLogger::class)->record('billing.plan.saved', metadata: ['key' => $plan->key, 'list_cents' => $plan->list_cents, 'floor_cents' => $plan->floor_cents], severity: 'notice', label: $plan->name);

            return $plan;
        });
    }

    /**
     * The list or floor price in force for brokerages on a date (old price during the notice period).
     */
    public function priceInForce(AgentPlan $plan, string $field, ?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $pending = DB::connection(Connections::control())->table('billing_price_notices')->where('agent_plan_id', $plan->id)
            ->where('field', $field)->where('effective_at', '>', $at)->orderBy('id')->first();

        return $pending !== null ? (int) $pending->old_cents : (int) $plan->getAttribute($field.'_cents');
    }

    /**
     * @param  list<array{min_paying_agents: int, percent: string|float}>  $tiers
     */
    public function saveTiers(array $tiers): void
    {
        usort($tiers, fn ($a, $b) => $a['min_paying_agents'] <=> $b['min_paying_agents']);
        if ($tiers === [] || $tiers[0]['min_paying_agents'] > 1) {
            throw ValidationException::withMessages(['tiers' => 'The first tier must start at 1 paying agent.']);
        }
        $db = DB::connection(Connections::control());
        $db->transaction(function () use ($db, $tiers): void {
            $db->table('billing_commission_tiers')->delete();
            foreach ($tiers as $t) {
                $db->table('billing_commission_tiers')->insert(['min_paying_agents' => $t['min_paying_agents'], 'percent' => $t['percent']]);
            }
        });
    }

    public function commissionPercent(int $payingAgents): string
    {
        $tier = DB::connection(Connections::control())->table('billing_commission_tiers')->where('min_paying_agents', '<=', max(1, $payingAgents))
            ->orderByDesc('min_paying_agents')->first();

        // Same text on every database: "20", "22.5".
        return $tier !== null ? rtrim(rtrim(number_format((float) $tier->percent, 2, '.', ''), '0'), '.') : '0';
    }
}
