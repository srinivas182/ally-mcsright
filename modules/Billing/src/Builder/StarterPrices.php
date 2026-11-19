<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Catalogue;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * PB3: RightAlly's starter prices, so a new install has something to sell from day one. HQ edits them like any other
 * package or plan; this only adds what is missing and never changes or replaces what HQ has saved.
 *
 * - Brokerage: $499 a month (or 10 months for a year) for the owner and 2 staff, with every brokerage module.
 * - Agent seats, three levels. An agent joining through a brokerage pays the floor price ($38 / $98 / $158); an agent
 *   joining on their own pays the public price ($58 / $138 / $198). The brokerage earns 20% of the floor.
 * - Solo agent packages at the public prices, for agents with no brokerage.
 *
 * These are business defaults for HQ to review (prices, the 20% share), not settled terms.
 */
final class StarterPrices
{
    public const BROKERAGE = ['name' => 'RightAlly Brokerage', 'monthly_cents' => 49_900, 'seats' => 3];

    /** Brokerage share of what its agents pay (on the floor price), from the first paying agent. */
    public const BROKERAGE_SHARE_PERCENT = 20;

    /** key => [name, about, modules, floor (through a brokerage), list (on their own)] */
    public const SEATS = [
        'basic' => ['Basic', 'Back office, CRM, deals, forms and e-sign.', ['back_office', 'crm', 'transactions'], 3_800, 5_800],
        'pro' => ['Pro', 'Basic plus AI for contacts, Today\'s 10, texting, calling, a website and email marketing.',
            ['back_office', 'crm', 'transactions', 'crm_ai', 'todays_ten', 'texting', 'calling', 'website', 'marketing'], 9_800, 13_800],
        'all_in' => ['All-in', 'Every module: Pro plus AI voice calls, Ally Studio, property look-ups, coaching and the AI blog writer.',
            ['back_office', 'crm', 'transactions', 'crm_ai', 'todays_ten', 'texting', 'calling', 'website', 'marketing', 'ai_voice', 'studio', 'property_data', 'learn', 'blog_writer'], 15_800, 19_800],
    ];

    public function __construct(private readonly PackageBuilder $builder, private readonly Catalogue $catalogue, private readonly AuditLogger $audit) {}

    /**
     * Adds whatever is missing. Returns what was added, in plain words.
     *
     * @return list<string>
     */
    public function install(User $by): array
    {
        $added = [];

        if (! Package::query()->where('audience', 'brokerage')->where('name', self::BROKERAGE['name'])->exists()) {
            $this->builder->save(null, ['name' => self::BROKERAGE['name'], 'audience' => 'brokerage', 'modules' => array_keys(ModuleCatalog::for('brokerage')),
                'seats' => self::BROKERAGE['seats'], 'price_cents' => self::BROKERAGE['monthly_cents'], 'reason' => 'Starter price'], $by);
            $added[] = self::BROKERAGE['name'].' package at $'.intdiv(self::BROKERAGE['monthly_cents'], 100).' a month';
        }

        $sort = 0;
        foreach (self::SEATS as $key => [$name, $about, $modules, $floor, $list]) {
            $sort++;
            $features = [];
            foreach ($modules as $m) {
                array_push($features, ...ModuleCatalog::MODULES[$m]['features']);
            }
            if (! AgentPlan::query()->where('key', $key)->exists()) {
                $this->catalogue->savePlan(null, ['key' => $key, 'name' => $name, 'description' => $about, 'list_cents' => $list, 'floor_cents' => $floor,
                    'features' => array_values(array_unique($features)), 'sort' => $sort]);
                $added[] = "{$name} agent seat at \$".intdiv($floor, 100).' through a brokerage, $'.intdiv($list, 100).' on their own';
            }
            $solo = 'Solo agent '.$name;
            if (! Package::query()->where('audience', 'agent')->where('name', $solo)->exists()) {
                $this->builder->save(null, ['name' => $solo, 'audience' => 'agent', 'modules' => $modules, 'seats' => 1, 'price_cents' => $list, 'reason' => 'Starter price'], $by);
                $added[] = "{$solo} package at \$".intdiv($list, 100).' a month';
            }
        }

        if (! DB::connection(Connections::control())->table('billing_commission_tiers')->exists()) {
            $this->catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => self::BROKERAGE_SHARE_PERCENT]]);
            $added[] = 'Brokerage share of '.self::BROKERAGE_SHARE_PERCENT.'% of what its agents pay';
        }

        if ($added !== []) {
            $this->audit->record('billing.starter_prices', metadata: ['added' => $added], severity: 'notice');
        }

        return $added;
    }
}
