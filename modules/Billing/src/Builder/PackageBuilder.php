<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * PB1: saves a package made in the builder — its modules, seats, included usage, markup and price — and keeps the
 * plan features and the metered allowances (texts, call minutes, map views, AI credits) in step with the modules.
 * A price below cost plus the minimum markup needs a written reason (kept with the package and in the audit log).
 */
final class PackageBuilder
{
    public function __construct(private readonly PackageQuote $quotes, private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, audience: string, modules: list<string>, seats: int, usage?: array<string, array<string, int>>, markup?: int|null, price_cents?: int|null, reason?: string|null, active?: bool}  $data
     */
    public function save(?Package $package, array $data, User $by): Package
    {
        if (! in_array($data['audience'], ['brokerage', 'agent'], true)) {
            throw ValidationException::withMessages(['audience' => 'Choose brokerages or solo agents.']);
        }
        $quote = $this->quotes->quote($data['audience'], $data['modules'], $data['seats'], $data['usage'] ?? [], $data['markup'] ?? null);
        $price = isset($data['price_cents']) ? max(0, (int) $data['price_cents']) : (int) $quote['price_cents'];
        PackageQuote::checkPrice($quote, $price, $data['reason'] ?? null);

        $features = [];
        foreach ($quote['modules'] as $key) {
            array_push($features, ...ModuleCatalog::MODULES[$key]['features']);
        }
        $included = $quote['included'];
        $package ??= new Package;
        $old = $package->exists ? ['monthly_cents' => $package->monthly_cents, 'modules' => $package->getAttribute('modules')] : null;
        $package->forceFill([
            'name' => mb_substr(trim($data['name']), 0, 80), 'audience' => $data['audience'], 'monthly_cents' => $price,
            'yearly_cents' => $price * PackageQuote::rules()['yearly_months'], 'features' => array_values(array_unique($features)),
            'modules' => $quote['modules'], 'seats' => $quote['seats'], 'usage' => $data['usage'] ?? [], 'markup_pct' => $quote['markup_pct'],
            'price_reason' => $price * 100 < (int) $quote['cost_cents'] * (100 + PackageQuote::rules()['min_markup']) ? mb_substr(trim((string) ($data['reason'] ?? '')), 0, 300) : null,
            'quote' => $quote + ['saved_price_cents' => $price],
            'included_text_segments' => $included['texts'] ?? 0, 'included_call_minutes' => $included['call_minutes'] ?? 0,
            'included_map_views' => $included['map_views'] ?? 0, 'included_credits' => $included['ai_credits'] ?? 0,
            'active' => $data['active'] ?? true,
        ])->save();
        $this->audit->record('billing.package.built', $package, changes: ['package' => ['old' => $old, 'new' => ['monthly_cents' => $price, 'modules' => $quote['modules']]]],
            metadata: ['cost_cents' => $quote['cost_cents'], 'markup_pct' => $quote['markup_pct'], 'reason' => $package->getAttribute('price_reason')], severity: 'notice');

        return $package;
    }

    /** HQ's pricing rules. */
    public function saveRules(int $markup, int $minMarkup, int $typicalUse, int $yearlyMonths, User $by): void
    {
        if ($markup < 0 || $markup > 500 || $minMarkup < 0 || $minMarkup > $markup || $typicalUse < 1 || $typicalUse > 100 || $yearlyMonths < 1 || $yearlyMonths > 12) {
            throw ValidationException::withMessages(['markup' => 'Markup 0–500%, the minimum no higher than the markup, typical use 1–100% and a year of 1–12 months.']);
        }
        $db = DB::connection(Connections::control())->table('credit_settings');
        $values = ['markup_pct' => $markup, 'min_markup_pct' => $minMarkup, 'typical_use_pct' => $typicalUse, 'yearly_months' => $yearlyMonths, 'updated_by' => $by->id, 'updated_at' => now()];
        $db->clone()->exists() ? $db->clone()->update($values) : $db->clone()->insert($values + ['created_at' => now()]);
        $this->audit->record('billing.package.rules', metadata: $values, severity: 'notice');
        // Credits follow the same rule: the credit pages work in margin, so keep them in step (70% markup = 41% margin).
        $rates = app(RateCard::class);
        $rates->saveMargins(intdiv($markup * 100, 100 + $markup), intdiv($minMarkup * 100, 100 + $minMarkup), $rates->margins()['usage_budget'], $by);
    }
}
