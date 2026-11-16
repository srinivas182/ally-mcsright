<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use Carbon\CarbonImmutable;
use Modules\Transactions\Models\Deal;

/**
 * The offer comparison (specs/ai/02, §16), worked out in code so every figure is exact:
 * - the price each offer comes to (escalation clauses applied against the other offers, up to their cap);
 * - net to the seller after the costs on the deal's net sheet and what the offer asks of the seller;
 * - how likely each offer is to close, with the reasons in plain English.
 */
final class Compare
{
    public const FINANCING = ['cash' => 'Cash', 'conventional' => 'Conventional', 'fha' => 'FHA', 'va' => 'VA', 'usda' => 'USDA', 'other' => 'Other'];

    public const PROOF = ['none' => 'None', 'prequal' => 'Pre-qualification', 'preapproval' => 'Pre-approval letter', 'proof_of_funds' => 'Proof of funds'];

    public const CONTINGENCIES = ['inspection' => 'Inspection', 'financing' => 'Financing', 'appraisal' => 'Appraisal', 'sale_of_home' => 'Sale of their home'];

    public const DEFAULT_SHEET = ['listing_percent' => 3.0, 'title_percent' => 1.0, 'payoff_cents' => 0, 'other_cents' => 0];

    /**
     * @return array{listing_percent: float, title_percent: float, payoff_cents: int, other_cents: int}
     */
    public static function sheet(Deal $deal): array
    {
        $s = (array) json_decode((string) ($deal->getAttribute('net_sheet') ?? ''), true);

        return ['listing_percent' => (float) ($s['listing_percent'] ?? self::DEFAULT_SHEET['listing_percent']), 'title_percent' => (float) ($s['title_percent'] ?? self::DEFAULT_SHEET['title_percent']),
            'payoff_cents' => (int) ($s['payoff_cents'] ?? 0), 'other_cents' => (int) ($s['other_cents'] ?? 0)];
    }

    /**
     * Each offer's effective price, with escalation clauses applied: the best competing price plus the step, up to
     * the cap, never below the offer's own price.
     *
     * @param  list<\stdClass>  $offers
     * @return array<int, int> offer id => cents
     */
    public static function prices(array $offers): array
    {
        $price = [];
        foreach ($offers as $o) {
            $price[(int) $o->id] = (int) $o->price_cents;
        }
        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($offers as $o) {
                if ($o->escalation_cap_cents === null || $o->escalation_step_cents === null) {
                    continue;
                }
                $others = array_diff_key($price, [(int) $o->id => true]);
                $best = $others === [] ? 0 : max($others);
                $price[(int) $o->id] = max((int) $o->price_cents, min((int) $o->escalation_cap_cents, $best + (int) $o->escalation_step_cents));
            }
        }

        return $price;
    }

    /**
     * @param  array{listing_percent: float, title_percent: float, payoff_cents: int, other_cents: int}  $sheet
     * @return array{price_cents: int, listing_cents: int, title_cents: int, concessions_cents: int, buyer_agent_cents: int, payoff_cents: int, other_cents: int, net_cents: int}
     */
    public static function net(\stdClass $offer, int $price, array $sheet): array
    {
        $listing = (int) round($price * $sheet['listing_percent'] / 100);
        $title = (int) round($price * $sheet['title_percent'] / 100);
        $net = $price - $listing - $title - (int) $offer->concessions_cents - (int) $offer->buyer_agent_cents - $sheet['payoff_cents'] - $sheet['other_cents'];

        return ['price_cents' => $price, 'listing_cents' => $listing, 'title_cents' => $title, 'concessions_cents' => (int) $offer->concessions_cents,
            'buyer_agent_cents' => (int) $offer->buyer_agent_cents, 'payoff_cents' => $sheet['payoff_cents'], 'other_cents' => $sheet['other_cents'], 'net_cents' => $net];
    }

    /**
     * How likely the offer is to close, 0–100, and why.
     *
     * @return array{score: int, grade: string, reasons: list<string>}
     */
    public static function certainty(\stdClass $o, int $listPrice): array
    {
        $score = 100;
        $reasons = [];
        $c = array_values(array_filter((array) json_decode((string) ($o->contingencies ?? '[]'), true), 'is_string'));
        $cash = $o->financing === 'cash';
        $minus = function (int $n, string $why) use (&$score, &$reasons): void {
            $score -= $n;
            $reasons[] = $why;
        };
        match ($o->financing) {
            'cash' => null,
            'conventional' => $minus(10, 'Loan needed (conventional)'),
            'fha', 'va', 'usda' => $minus(15, 'Government loan ('.strtoupper((string) $o->financing).'): stricter property and appraisal rules'),
            default => $minus(15, 'Financing not stated'),
        };
        match (true) {
            $cash && $o->proof === 'proof_of_funds' => null,
            $cash => $minus(15, 'Cash, but no proof of funds yet'),
            $o->proof === 'preapproval' => null,
            $o->proof === 'prequal' => $minus(10, 'Pre-qualified only, not pre-approved'),
            default => $minus(20, 'No pre-approval letter'),
        };
        if (in_array('sale_of_home', $c, true)) {
            $minus(20, 'Depends on selling their own home');
        }
        if (in_array('financing', $c, true) && ! $cash) {
            $minus(10, 'Can walk away if the loan falls through');
        }
        if (in_array('appraisal', $c, true)) {
            $gap = (int) ($o->appraisal_gap_cents ?? 0);
            $gap > 0 && $listPrice > 0 && $gap >= (int) round($listPrice * 0.05) ? $minus(3, 'Appraisal contingency, with a strong appraisal gap') : $minus(10, 'Can walk away if the appraisal comes in low');
        } elseif ((int) ($o->appraisal_gap_cents ?? 0) > 0) {
            $reasons[] = '+ Covers an appraisal gap';
        }
        if (in_array('inspection', $c, true)) {
            $minus((int) ($o->option_days ?? 0) > 10 ? 10 : 5, 'Inspection contingency'.((int) ($o->option_days ?? 0) > 0 ? ' ('.(int) $o->option_days.' days)' : ''));
        }
        if ($o->closing_date !== null && CarbonImmutable::parse((string) $o->closing_date)->gt(CarbonImmutable::today()->addDays(60))) {
            $minus(5, 'Closes more than 60 days out');
        }
        if ($o->expires_at !== null && CarbonImmutable::parse((string) $o->expires_at)->isPast()) {
            $reasons[] = 'The offer has expired';
        }
        $score = max(0, $score);

        return ['score' => $score, 'grade' => $score >= 80 ? 'Strong' : ($score >= 60 ? 'Fair' : 'Weaker'), 'reasons' => $reasons];
    }

    /**
     * The whole comparison, best net first. Buyers' names are never included (what the seller sees, fair housing).
     *
     * @param  list<\stdClass>  $offers
     * @return list<array<string, mixed>>
     */
    public static function table(Deal $deal, array $offers, bool $forAgent = true): array
    {
        $sheet = self::sheet($deal);
        $prices = self::prices($offers);
        $rows = [];
        foreach ($offers as $o) {
            $net = self::net($o, $prices[(int) $o->id], $sheet);
            $row = ['ulid' => (string) $o->ulid, 'label' => 'Offer '.$o->label, 'status' => (string) $o->status, 'round' => (int) $o->round,
                'price_cents' => (int) $o->price_cents, 'effective_cents' => $prices[(int) $o->id], 'escalates' => $o->escalation_cap_cents !== null,
                'escalation_cap_cents' => $o->escalation_cap_cents !== null ? (int) $o->escalation_cap_cents : null,
                'financing' => self::FINANCING[(string) $o->financing] ?? 'Not stated', 'down_percent' => $o->down_percent !== null ? (float) $o->down_percent : null,
                'proof' => self::PROOF[(string) $o->proof] ?? 'None', 'earnest_cents' => $o->earnest_cents !== null ? (int) $o->earnest_cents : null,
                'option_days' => $o->option_days !== null ? (int) $o->option_days : null, 'closing_date' => $o->closing_date !== null ? substr((string) $o->closing_date, 0, 10) : null,
                'contingencies' => array_values(array_map(fn ($k): string => self::CONTINGENCIES[$k] ?? (string) $k, array_filter((array) json_decode((string) ($o->contingencies ?? '[]'), true), 'is_string'))),
                'appraisal_gap_cents' => $o->appraisal_gap_cents !== null ? (int) $o->appraisal_gap_cents : null, 'leaseback_days' => $o->leaseback_days !== null ? (int) $o->leaseback_days : null,
                'expires_at' => $o->expires_at !== null ? CarbonImmutable::parse((string) $o->expires_at)->toIso8601String() : null,
                'net' => $net, 'certainty' => self::certainty($o, $deal->price_cents)];
            if ($forAgent) {
                $row += ['agent_name' => $o->agent_name, 'agent_email' => $o->agent_email, 'agent_phone' => $o->agent_phone, 'brokerage' => $o->brokerage,
                    'buyer_names' => $o->buyer_names, 'notes' => $o->notes, 'source' => (string) $o->source, 'has_file' => $o->file_path !== null, 'packet' => $o->packet,
                    'read' => (array) json_decode((string) ($o->read ?? '[]'), true)];
            }
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b): int => $b['net']['net_cents'] <=> $a['net']['net_cents']);

        return $rows;
    }
}
