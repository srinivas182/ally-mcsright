<?php

declare(strict_types=1);

/*
 * Pricing defaults (CM1, specs/listings/01). Every value here is a starting point marked "review" in Settings:
 * brokerages can change their own, and HQ should confirm them with a title company and a lender per state before
 * launch. Percentages are of the price (or the loan, where said).
 */
return [
    // Comparable sales: how far back and how far out to look, widening until there are enough.
    'comps' => [
        'wanted' => 6,
        'months' => [6, 12],
        'miles' => [1, 2, 3],
        'size' => [0.25, 0.40], // ± share of the subject's living area
    ],

    // Adjustments, applied to each comp's sold price to make it like the subject home.
    'adjustments' => [
        'size_factor' => 0.5,   // of the comps' median price per square foot, for each square foot of difference
        'bed_pct' => 1.5,       // of the comps' median price, per bedroom
        'full_bath_pct' => 1.5,
        'half_bath_pct' => 0.75,
        'age_pct' => 0.25,      // per year of difference in age, up to 'age_cap' years
        'age_cap' => 30,
        'pool_pct' => 2.0,
        'garage_pct' => 1.0,    // per garage space, up to 3
        'lot_pct' => 0.5,       // per 10% difference in lot size, up to ±5% of the price
    ],

    // Typical closing costs, by state (review).
    'seller_closing_pct' => ['TX' => 1.5, 'MS' => 1.0, 'LA' => 1.0, 'AL' => 1.0, 'default' => 1.2],
    'buyer_closing_pct' => ['TX' => 2.0, 'MS' => 2.5, 'LA' => 2.5, 'AL' => 2.5, 'default' => 2.5],
    'property_tax_pct' => ['TX' => 1.8, 'MS' => 0.8, 'LA' => 0.55, 'AL' => 0.4, 'default' => 1.1], // a year, of the price
    'insurance_pct' => ['TX' => 0.9, 'MS' => 0.9, 'LA' => 1.2, 'AL' => 0.8, 'default' => 0.5],     // a year, of the price

    'listing_commission_pct' => 3.0, // shown as negotiable
    'buyer_agent_pct' => 2.5,        // shown as negotiable and the seller's choice
    'interest_rate' => 6.5,          // review often: rates change
    'loan_years' => 30,

    // Loan types: smallest down payment, and mortgage insurance.
    'loans' => [
        'conventional' => ['min_down' => 3.0, 'pmi_pct' => 0.5, 'pmi_until_down' => 20.0, 'upfront_pct' => 0.0],
        'fha' => ['min_down' => 3.5, 'pmi_pct' => 0.55, 'pmi_until_down' => 100.0, 'upfront_pct' => 1.75],
        'va' => ['min_down' => 0.0, 'pmi_pct' => 0.0, 'pmi_until_down' => 0.0, 'upfront_pct' => 2.15],
    ],

    // Share links.
    'share_days' => 90,
];
