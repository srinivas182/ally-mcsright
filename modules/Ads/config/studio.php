<?php

declare(strict_types=1);

/*
| Ally Studio render workers (specs/grow/13, SA1b): designs are HTML and CSS, rendered to images by headless Chrome
| on the queue workers. Set STUDIO_CHROME to the browser on the worker; STUDIO_CHROME_NO_SANDBOX only where the
| worker runs Chrome as root inside a container that is itself isolated.
*/

return [
    'renderer' => env('STUDIO_RENDERER', 'chrome'), // chrome | fake (tests)
    'chrome' => env('STUDIO_CHROME', '/usr/bin/google-chrome'),
    'no_sandbox' => (bool) env('STUDIO_CHROME_NO_SANDBOX', false),
    'timeout' => (int) env('STUDIO_RENDER_TIMEOUT', 45),
    'daily_sets' => (int) env('STUDIO_DAILY_SETS', 40), // design sets one person may make a day
];
