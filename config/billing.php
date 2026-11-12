<?php

declare(strict_types=1);

return [
    // Agents pay RightAlly directly by default for every brokerage (owner decision, 10A-2). HQ can change
    // it in the console; this only sets the first value. Tests of other modules run with it off.
    'agent_pays_default' => (bool) env('BILLING_AGENT_PAYS_DEFAULT', true),
];
