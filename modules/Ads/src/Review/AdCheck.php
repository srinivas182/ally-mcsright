<?php

declare(strict_types=1);

namespace Modules\Ads\Review;

use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Models\Listing;

/**
 * The marketing check (MK3, specs/ads/06): reads an ad, flyer, post or email the way a compliance broker would and
 * marks every problem in place, with why it matters and a fix. Rules, not guesses, so the same text always gets the
 * same answer and nothing costs credits; an AI rewrite is a separate, optional step.
 *
 * Checks: Fair Housing wording; the brokerage named (state advertising rules); facts against the MLS (price, beds,
 * baths, square feet, status) when a listing is named; guarantees and unsupported "#1" claims (truth in advertising,
 * NAR Code Article 12); financing "trigger terms" that need full Reg Z disclosure; square footage without a source;
 * and Equal Housing Opportunity on print. A guardrail, not legal advice.
 */
final class AdCheck
{
    public const KINDS = ['flyer' => 'Flyer or print', 'social' => 'Social post', 'ad' => 'Online ad', 'email' => 'Email or newsletter', 'sign' => 'Sign or postcard'];

    /** Neutral wording for Fair Housing phrases (describe the property, not who should live there). */
    private const INSTEAD = [
        'safe neighborhood' => 'quiet street', 'safe neighbourhood' => 'quiet street', 'safe area' => 'close to parks and shops', 'safe community' => 'well-kept community',
        'safe place to live' => 'well-kept home', 'family-friendly' => 'spacious', 'family friendly' => 'spacious', 'good for families' => 'with room to grow',
        'perfect for families' => 'with room to grow', 'ideal for families' => 'with room to grow', 'young professionals' => 'commuters', 'empty nesters' => 'anyone wanting less upkeep',
        'perfect for young' => 'perfect for', 'great for kids' => 'with a large yard', 'exclusive neighborhood' => 'sought-after location', 'no children' => '',
        'adults only' => '', 'bachelor pad' => 'stylish one-bedroom', 'traditional neighborhood' => 'established neighborhood', 'handicap' => 'accessible',
    ];

    /** Phrases that promise outcomes or claim rank without a source. */
    private const CLAIMS = ['guaranteed sale', 'guarantee your home will sell', 'guaranteed to sell', 'we guarantee', 'best price guaranteed', 'sold in 24 hours', '#1 agent', 'number one agent',
        '#1 brokerage', 'number 1 agent', 'top agent in', 'best agent in', 'will sell in', 'highest price guaranteed', 'risk-free', 'no risk'];

    /** Reg Z (TILA) trigger terms: any of these in an ad means the full credit terms and APR must be shown. */
    private const TRIGGERS = ['/\b\d+(\.\d+)?\s*%\s*down\b/i', '/\b(only|just)\s*\$?\d[\d,]*\s*(a|per)\s*month\b/i', '/\$\d[\d,]*\s*(\/|a|per)\s*(mo|month)\b/i',
        '/\b\d+(\.\d+)?\s*%\s*(interest|rate)\b/i', '/\b(\d+|thirty|fifteen)[\s-]*year\s*(fixed|loan|mortgage)\b/i', '/\bno\s*money\s*down\b/i'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{findings: list<array{rule: string, severity: string, text: string|null, why: string, fix: string, replace: string|null}>, fixed: string, ready: bool, listing: array<string, mixed>|null}
     */
    public function run(string $text, string $kind, ?string $mlsNumber = null): array
    {
        $text = trim($text);
        $lower = mb_strtolower($text);
        $findings = [];
        $fixed = $text;

        foreach (FairHousing::screen($text, 'ads', 'warned', 'Marketing check') as $phrase) {
            $instead = self::INSTEAD[$phrase] ?? null;
            $findings[] = ['rule' => 'fair_housing', 'severity' => 'must_fix', 'text' => $phrase,
                'why' => 'Fair Housing: words about who a home suits, or about an area\'s safety or people, can be read as steering. Describe the property instead.',
                'fix' => $instead !== null && $instead !== '' ? 'Say "'.$instead.'" instead.' : 'Remove it.', 'replace' => $instead];
            if ($instead !== null) {
                $fixed = (string) preg_replace('/'.preg_quote($phrase, '/').'/i', $instead, $fixed);
            }
        }

        $brokerage = $this->tenants->current()->name;
        if (! str_contains($lower, mb_strtolower($brokerage))) {
            $findings[] = ['rule' => 'brokerage_name', 'severity' => 'must_fix', 'text' => null,
                'why' => 'State advertising rules require the brokerage\'s name to be clear in every ad, not only the agent\'s.',
                'fix' => 'Add "'.$brokerage.'" (your brand kit adds it to designs automatically).', 'replace' => null];
            $fixed .= "\n".$brokerage;
        }

        foreach (self::CLAIMS as $claim) {
            if (str_contains($lower, $claim)) {
                $findings[] = ['rule' => 'claims', 'severity' => 'must_fix', 'text' => $claim,
                    'why' => 'Promises of an outcome, or "#1" and "best" without a source and date, can mislead (truth in advertising; NAR Code of Ethics, Article 12).',
                    'fix' => 'Remove it, or state the source and period (for example "Most homes sold in Travis County, 2026, per MLS").', 'replace' => null];
            }
        }

        foreach (self::TRIGGERS as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $findings[] = ['rule' => 'financing', 'severity' => 'must_fix', 'text' => $m[0],
                    'why' => 'This is a financing "trigger term" (Truth in Lending, Regulation Z): an ad that uses it must also show the full terms, including the APR.',
                    'fix' => 'Remove the financing terms, or add the full disclosure from the lender (down payment, terms of repayment and APR).', 'replace' => null];
                break;
            }
        }

        if (preg_match('/\b\d[\d,]{2,6}\s*(sq\.?\s*ft|square feet|sf)\b/i', $text) === 1 && preg_match('/\b(approx|approximately|per (tax|appraisal|appraiser|builder|mls)|about)\b/i', $text) !== 1) {
            $findings[] = ['rule' => 'square_feet', 'severity' => 'should_fix', 'text' => null,
                'why' => 'Square footage varies by source; stating it without one invites disputes.',
                'fix' => 'Add "approx." or the source, for example "per appraisal".', 'replace' => null];
        }

        if (in_array($kind, ['flyer', 'sign'], true) && ! str_contains($lower, 'equal housing')) {
            $findings[] = ['rule' => 'equal_housing', 'severity' => 'should_fix', 'text' => null,
                'why' => 'HUD guidance asks for the Equal Housing Opportunity statement or logo on printed housing ads.',
                'fix' => 'Add "Equal Housing Opportunity" (your brand kit designs include the logo).', 'replace' => null];
            $fixed .= "\nEqual Housing Opportunity";
        }

        $listing = null;
        if ($mlsNumber !== null && trim($mlsNumber) !== '') {
            [$listing, $factFindings] = $this->facts($text, trim($mlsNumber));
            array_push($findings, ...$factFindings);
        }

        return ['findings' => $findings, 'fixed' => trim($fixed), 'ready' => ! in_array('must_fix', array_column($findings, 'severity'), true), 'listing' => $listing];
    }

    /**
     * The ad's facts against the MLS listing: what it says, what the MLS says.
     *
     * @return array{0: array<string, mixed>|null, 1: list<array{rule: string, severity: string, text: string|null, why: string, fix: string, replace: string|null}>}
     */
    private function facts(string $text, string $mls): array
    {
        $row = Listing::query()->visibleTo($this->tenants->id(), 'idx')->where('listing_id', $mls)->with('source.rulesProfile')->first();
        $shown = $row !== null ? ListingPresenter::forDisplay($row) : null;
        if ($row === null || $shown === null) {
            return [null, [['rule' => 'mls', 'severity' => 'should_fix', 'text' => null, 'why' => 'We could not find MLS# '.$mls.' in your MLS feed, so the facts could not be checked.',
                'fix' => 'Check the MLS number. Coming-soon listings must be in the MLS within one business day of public marketing (Clear Cooperation).', 'replace' => null]]];
        }
        $out = [];
        $says = fn (string $label, string $ad, string $mlsValue): array => ['rule' => 'mls', 'severity' => 'must_fix', 'text' => $ad,
            'why' => 'The ad says '.$ad.'; the MLS says '.$mlsValue.'.', 'fix' => 'Use the MLS figure ('.$mlsValue.'), or correct the MLS first.', 'replace' => null];
        if (preg_match('/\$\s?(\d[\d,]*(\.\d+)?)\s*(k|m)?\b/i', $text, $m) === 1 && $shown['price'] !== null) {
            $n = (float) str_replace(',', '', $m[1]);
            $price = (int) round(match (strtolower($m[3] ?? '')) {
                'k' => $n * 1000, 'm' => $n * 1_000_000, default => $n
            });
            if ($price >= 10000 && abs($price - (int) $shown['price']) > max(1000, (int) $shown['price'] * 0.005)) {
                $out[] = $says('price', $m[0], '$'.number_format((int) $shown['price']));
            }
        }
        foreach (['beds' => '/(\d+)\s*(bed|beds|bedroom|bedrooms|bd|br)\b/i', 'baths_full' => '/(\d+)(\.\d)?\s*(bath|baths|bathroom|bathrooms|ba)\b/i'] as $field => $pattern) {
            if (preg_match($pattern, $text, $m) === 1 && $shown[$field] !== null && (int) $m[1] !== (int) $shown[$field]) {
                $out[] = $says($field, $m[0], $shown[$field].($field === 'beds' ? ' bedrooms' : ' full bathrooms'));
            }
        }
        if (preg_match('/(\d[\d,]{2,6})\s*(sq\.?\s*ft|square feet|sf)\b/i', $text, $m) === 1 && $shown['sqft'] !== null) {
            $sq = (int) str_replace(',', '', $m[1]);
            if (abs($sq - (int) $shown['sqft']) > max(25, (int) $shown['sqft'] * 0.02)) {
                $out[] = $says('sqft', $m[0], number_format((int) $shown['sqft']).' sq ft');
            }
        }
        $status = (string) ($row->standard_status ?? '');
        if (in_array($status, ['Pending', 'Active Under Contract', 'Closed'], true) && preg_match('/\b(just listed|for sale|new listing|available now)\b/i', $text, $m) === 1) {
            $out[] = ['rule' => 'mls', 'severity' => 'must_fix', 'text' => $m[0], 'why' => 'The MLS shows this home as '.$status.', so "'.$m[0].'" is no longer accurate.',
                'fix' => 'Say "'.($status === 'Closed' ? 'Just sold' : 'Under contract').'" instead.', 'replace' => null];
        }

        return [['mls_number' => $mls, 'address' => trim(($shown['address'] ?? '').', '.($shown['city'] ?? ''), ', '), 'price' => $shown['price'], 'beds' => $shown['beds'],
            'baths' => $shown['baths_full'], 'sqft' => $shown['sqft'], 'status' => $status], $out];
    }
}
