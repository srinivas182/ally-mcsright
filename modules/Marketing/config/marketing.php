<?php

declare(strict_types=1);

return [
    // RightAlly's public website. The first host is the canonical one; the others redirect to it.
    'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARKETING_HOSTS', 'rightally.io,www.rightally.io'))))),
    'organization' => [
        'name' => 'RightAlly',
        'same_as' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARKETING_SAME_AS', ''))))),
    ],
];
