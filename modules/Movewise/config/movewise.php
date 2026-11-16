<?php

declare(strict_types=1);

/*
 * Movewise Homes (specs/movewise/00): HQ's consumer site. One public host; the other registered domains send
 * visitors there with a permanent redirect that keeps the path.
 */
return [
    'host' => env('MOVEWISE_HOST', 'movewisehomes.com'),
    'redirect_hosts' => array_values(array_filter(explode(',', (string) env('MOVEWISE_REDIRECT_HOSTS', 'movewise.homes,www.movewise.homes,movewiserealestate.com,www.movewiserealestate.com,www.movewisehomes.com')))),
    'mail_from' => env('MOVEWISE_MAIL_FROM', 'hello@movewisehomes.com'),
    'postal_address' => env('MOVEWISE_POSTAL_ADDRESS', ''), // required on alert emails (CAN-SPAM)
    'launch_states' => ['TX' => 'Texas', 'LA' => 'Louisiana', 'MS' => 'Mississippi', 'AL' => 'Alabama'],

    // True monthly cost (estimates the visitor can change; each line says what it assumes).
    'mortgage_rate' => (float) env('MOVEWISE_RATE', 6.5),     // % a year, 30-year fixed
    'down_payment' => 10,                                     // % of the price
    'tax_rate' => ['TX' => 1.8, 'LA' => 0.55, 'MS' => 0.75, 'AL' => 0.4],          // % of value a year, typical for the state
    'insurance_rate' => ['TX' => 0.95, 'LA' => 1.1, 'MS' => 0.85, 'AL' => 0.8],    // % of value a year, typical homeowners cover
    'utilities' => 280,                                       // $ a month, typical for a family home

    // Choosing an agent (specs/movewise/04).
    'accept_minutes' => (int) env('MOVEWISE_ACCEPT_MINUTES', 5),          // to accept inside working hours
    'after_hours_minutes' => (int) env('MOVEWISE_AFTER_HOURS_MINUTES', 30), // after the next start, when chosen outside them
    'fresh_days' => 7,                                        // one of the three places goes to an agent not shown in this many days
    'refund_days' => 7,

    // Launch (specs/movewise/08): the first state, and how long it runs before the next may go live.
    'launch_order' => ['TX', 'LA', 'MS', 'AL'],
    'launch_gap_days' => (int) env('MOVEWISE_LAUNCH_GAP_DAYS', 30),                                       // an agent can report a wrong number for this long after accepting
];
