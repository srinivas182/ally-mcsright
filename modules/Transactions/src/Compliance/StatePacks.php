<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use Illuminate\Support\Collection;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;

/**
 * State rule packs (RD1, specs/transactions/31): the disclosures each state expects in a file, on top of the
 * brokerage's own checklist. A document counts when a checklist item or an uploaded document's name or type mentions
 * it. Each rule is a compliance check the brokerage can switch off or make a blocker (Settings → Transactions).
 * Starting points to review with the brokerage's attorney; not legal advice.
 */
final class StatePacks
{
    /** @var array<string, array{states: list<string>, label: string, types: list<string>, from: string, words: list<string>, why: string}> */
    public const RULES = [
        'state.tx_iabs' => ['states' => ['TX'], 'label' => 'Information About Brokerage Services (IABS)', 'types' => ['listing', 'buyer', 'dual', 'lease'], 'from' => 'pre_contract',
            'words' => ['iabs', 'information about brokerage services'], 'why' => 'TREC requires the IABS notice at the first substantive conversation.'],
        'state.tx_seller_disclosure' => ['states' => ['TX'], 'label' => 'Seller\'s Disclosure Notice', 'types' => ['listing', 'dual'], 'from' => 'under_contract',
            'words' => ['seller\'s disclosure', 'sellers disclosure', 'seller disclosure'], 'why' => 'Texas Property Code §5.008 requires it for most resale homes.'],
        'state.ca_agency' => ['states' => ['CA'], 'label' => 'Disclosure Regarding Real Estate Agency Relationship (AD)', 'types' => ['listing', 'buyer', 'dual'], 'from' => 'pre_contract',
            'words' => ['agency relationship', 'agency disclosure', ' ad form'], 'why' => 'California Civil Code §2079.14 requires the agency disclosure.'],
        'state.ca_tds' => ['states' => ['CA'], 'label' => 'Transfer Disclosure Statement (TDS)', 'types' => ['listing', 'dual'], 'from' => 'under_contract',
            'words' => ['transfer disclosure', 'tds'], 'why' => 'California Civil Code §1102 requires the TDS for most residential sales.'],
        'state.fl_relationship' => ['states' => ['FL'], 'label' => 'Brokerage relationship disclosure', 'types' => ['listing', 'buyer', 'dual'], 'from' => 'pre_contract',
            'words' => ['brokerage relationship', 'transaction broker notice', 'single agent notice'], 'why' => 'Florida Statute §475.278 requires the brokerage relationship disclosure.'],
        'state.ny_agency' => ['states' => ['NY'], 'label' => 'New York agency disclosure form', 'types' => ['listing', 'buyer', 'dual', 'lease'], 'from' => 'pre_contract',
            'words' => ['agency disclosure'], 'why' => 'NY Real Property Law §443 requires it at the first substantive contact.'],
        'state.ny_fair_housing' => ['states' => ['NY'], 'label' => 'NY Housing and Anti-Discrimination Disclosure', 'types' => ['listing', 'buyer', 'dual', 'lease'], 'from' => 'pre_contract',
            'words' => ['anti-discrimination', 'housing and anti discrimination'], 'why' => 'New York requires this disclosure alongside the agency form.'],
        'state.az_spds' => ['states' => ['AZ'], 'label' => 'Seller\'s Property Disclosure Statement (SPDS)', 'types' => ['listing', 'dual'], 'from' => 'under_contract',
            'words' => ['spds', 'seller\'s property disclosure', 'sellers property disclosure'], 'why' => 'Arizona practice and the AAR contract expect the SPDS.'],
        'state.co_brokerage_disclosure' => ['states' => ['CO'], 'label' => 'Brokerage Disclosure to Buyer or Seller', 'types' => ['listing', 'buyer', 'dual'], 'from' => 'pre_contract',
            'words' => ['brokerage disclosure'], 'why' => 'Colorado Real Estate Commission rules require the brokerage disclosure.'],
        'state.ms_working_relationships' => ['states' => ['MS'], 'label' => 'Working Relationships Disclosure', 'types' => ['listing', 'buyer', 'dual'], 'from' => 'pre_contract',
            'words' => ['working relationship'], 'why' => 'The Mississippi Real Estate Commission requires the working relationships disclosure.'],
    ];

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, DocumentReading>  $readings
     * @param  Collection<int, Document>  $docs
     * @return list<array{code: string, key: string, message: string, item: string|null}>
     */
    public static function missing(Deal $deal, $items, $readings, $docs): array
    {
        $order = ['pre_contract' => 0, 'under_contract' => 1, 'closed' => 2, 'fell_through' => -1];
        $text = mb_strtolower(' '.$items->filter(fn (Item $i): bool => in_array($i->status, ['submitted', 'approved', 'waived'], true))->pluck('label')->implode(' | ')
            .' | '.$docs->pluck('filename')->implode(' | ').' | '.$readings->pluck('doc_type')->implode(' | ').' ');
        $out = [];
        foreach (self::RULES as $code => $r) {
            if (! in_array(strtoupper($deal->state_code), $r['states'], true) || ! in_array($deal->type, $r['types'], true)
                || ($order[$deal->status] ?? -1) < $order[$r['from']]) {
                continue;
            }
            $found = collect($r['words'])->contains(fn (string $w): bool => str_contains($text, $w));
            if (! $found) {
                $item = $items->first(fn (Item $i): bool => collect($r['words'])->contains(fn (string $w): bool => str_contains(' '.mb_strtolower($i->label).' ', $w)));
                $out[] = ['code' => $code, 'key' => $code, 'message' => $r['label'].' is not in the file. '.$r['why'], 'item' => $item?->ulid];
            }
        }

        return $out;
    }
}
