<?php

declare(strict_types=1);

return [
    // Agents pay RightAlly directly by default for every brokerage (owner decision, 10A-2). HQ can change
    // it in the console; this only sets the first value. Tests of other modules run with it off.
    'agent_pays_default' => (bool) env('BILLING_AGENT_PAYS_DEFAULT', true),
    // 1099-NEC threshold for years after 2026 (indexed for inflation from 2027; HQ updates it each year).
    'nec_threshold_cents' => (int) env('BILLING_NEC_THRESHOLD_CENTS', 200_000),
];
