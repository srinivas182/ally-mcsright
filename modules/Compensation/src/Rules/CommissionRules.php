<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Exceptions\InvalidRules;

/**
 * Validated rules of a commission plan version (specs/compensation/01). Unknown keys are rejected
 * so mistakes cannot hide in the JSON. Team overrides arrive in Sprint 5; their keys are accepted
 * only once that part ships.
 */
final readonly class CommissionRules
{
    private const KEYS = ['split', 'pre_split', 'post_split', 'deal_classes', 'lead_sources', 'revenue_share', 'cap', 'post_cap', 'fees'];

    /**
     * @param  list<Deduction>  $preSplit
     * @param  list<Deduction>  $postSplit
     * @param  array<string, Split>  $dealClasses  keyed by DealClass value
     * @param  array<string, Split>  $leadSources  keyed by lead-source category
     * @param  list<string>  $feeCodes  catalogue fee codes
     */
    public function __construct(
        public Split $split,
        public array $preSplit = [],
        public array $postSplit = [],
        public array $dealClasses = [],
        public array $leadSources = [],
        public ?string $revenueShareBasis = null,
        public ?Cap $cap = null,
        public array $feeCodes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidRules
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        foreach (array_diff(array_keys($data), self::KEYS) as $unknown) {
            $errors[(string) $unknown] = 'Unknown rule.';
        }

        $split = Split::parse($data['split'] ?? null, 'split', $errors);
        $pre = Deduction::parseList($data['pre_split'] ?? null, 'pre_split', $errors);
        $post = Deduction::parseList($data['post_split'] ?? null, 'post_split', $errors);

        $classes = [];
        foreach ((array) ($data['deal_classes'] ?? []) as $class => $override) {
            if (DealClass::tryFrom((string) $class) === null) {
                $errors["deal_classes.{$class}"] = 'Unknown deal class.';

                continue;
            }
            $parsed = Split::parse(is_array($override) ? ($override['split'] ?? null) : null, "deal_classes.{$class}.split", $errors);
            if ($parsed !== null) {
                $classes[(string) $class] = $parsed;
            }
        }

        $sources = [];
        foreach ((array) ($data['lead_sources'] ?? []) as $category => $override) {
            if (preg_match('/^[a-z0-9_]{2,40}$/', (string) $category) !== 1) {
                $errors["lead_sources.{$category}"] = 'Use a short category like company_lead.';

                continue;
            }
            $parsed = Split::parse(is_array($override) ? ($override['split'] ?? null) : null, "lead_sources.{$category}.split", $errors);
            if ($parsed !== null) {
                $sources[(string) $category] = $parsed;
            }
        }

        $basis = null;
        if (isset($data['revenue_share'])) {
            $basis = is_array($data['revenue_share']) ? ($data['revenue_share']['basis'] ?? null) : null;
            if (! in_array($basis, ['net_profit', 'gci'], true)) {
                $errors['revenue_share.basis'] = 'Revenue share is based on net_profit or gci.';
                $basis = null;
            }
        }

        $cap = Cap::parse($data['cap'] ?? null, $data['post_cap'] ?? null, $errors);

        if ($errors !== [] || $split === null) {
            throw new InvalidRules($errors === [] ? ['split' => 'A split is required.'] : $errors);
        }

        $fees = [];
        foreach ((array) ($data['fees'] ?? []) as $i => $code) {
            if (! is_string($code) || preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1) {
                $errors["fees.{$i}"] = 'Fees are catalogue codes like tech_fee.';

                continue;
            }
            $fees[] = $code;
        }
        if ($errors !== []) {
            throw new InvalidRules($errors);
        }

        return new self($split, $pre, $post, $classes, $sources, $basis, $cap, array_values(array_unique($fees)));
    }

    /**
     * The same rules with catalogue fees added (resolved at the closing date).
     *
     * @param  list<Deduction>  $pre
     * @param  list<Deduction>  $post
     */
    public function withCatalogueFees(array $pre, array $post): self
    {
        return new self($this->split, [...$this->preSplit, ...$pre], [...$this->postSplit, ...$post], $this->dealClasses,
            $this->leadSources, $this->revenueShareBasis, $this->cap, $this->feeCodes);
    }
}
