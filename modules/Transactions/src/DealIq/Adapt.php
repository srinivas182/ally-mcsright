<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Modules\Core\Audit\AuditLogger;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\DocIq;

/**
 * Checklists that adapt to the home and the money (specs/ai/02, §8): a condo needs association documents, land a
 * survey, an FHA or VA loan its amendatory clause, a home built before 1978 the lead-based paint disclosure. Items are
 * added once and never removed automatically, so nothing the broker relies on disappears.
 */
final class Adapt
{
    public const PROPERTY_TYPES = ['single_family' => 'Single-family home', 'condo' => 'Condo', 'townhome' => 'Townhome (with HOA)', 'multi_family' => 'Multi-family (2–4 units)',
        'land' => 'Land or lot', 'manufactured' => 'Manufactured home'];

    public const FINANCING = ['cash' => 'Cash', 'conventional' => 'Conventional loan', 'fha' => 'FHA loan', 'va' => 'VA loan', 'usda' => 'USDA loan', 'other' => 'Other'];

    /** @var array<string, list<array{0: string, 1: bool}>> what => [label, needed to close] */
    private const RULES = [
        'condo' => [['Condominium association documents and resale certificate', true]],
        'townhome' => [['HOA documents and resale certificate', true]],
        'multi_family' => [['Rent roll and current leases', true], ['Tenant estoppel letters', false]],
        'land' => [['Survey', true], ['Zoning or land-use confirmation', false]],
        'manufactured' => [['Title or certificate of the manufactured home', true]],
        'fha' => [['FHA amendatory clause', true]],
        'va' => [['VA amendatory (escape) clause', true]],
        'usda' => [['USDA loan approval', false]],
        'cash' => [['Proof of funds', true]],
        'pre1978' => [['Lead-based paint disclosure', true]],
    ];

    /**
     * @return list<string> labels added
     */
    public function apply(Deal $deal): array
    {
        $keys = array_filter([$deal->property_type, $deal->financing]);
        $built = collect(DocIq::dealFacts($deal))->firstWhere('name', 'year_built');
        if ($built !== null && preg_match('/\b(1[6-9]\d\d)\b/', (string) $built['value'], $m) === 1 && (int) $m[1] < 1978 && $deal->type !== 'lease') {
            $keys[] = 'pre1978';
        }
        $have = Item::query()->where('deal_id', $deal->id)->pluck('label')->map(fn ($l): string => mb_strtolower((string) $l))->all();
        $added = [];
        $sort = (int) Item::query()->where('deal_id', $deal->id)->max('sort');
        foreach ($keys as $key) {
            foreach (self::RULES[$key] ?? [] as [$label, $required]) {
                if (in_array(mb_strtolower($label), $have, true)) {
                    continue;
                }
                Item::query()->create(['deal_id' => $deal->id, 'label' => $label, 'required' => $required, 'stage' => 'contract', 'sort' => ++$sort, 'status' => 'missing']);
                $have[] = mb_strtolower($label);
                $added[] = $label;
            }
        }
        if ($added !== []) {
            app(AuditLogger::class)->record('transactions.checklist.adapted', $deal, metadata: ['added' => $added, 'property_type' => $deal->property_type, 'financing' => $deal->financing], label: $deal->street);
        }

        return $added;
    }
}
