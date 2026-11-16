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

    // Reels and Shorts (SA3): ffmpeg on the same workers turns a design's story frames into a 9:16 video.
    'video' => env('STUDIO_VIDEO', 'ffmpeg'), // ffmpeg | fake (tests)
    'ffmpeg' => env('STUDIO_FFMPEG', '/usr/bin/ffmpeg'),
    'video_timeout' => (int) env('STUDIO_VIDEO_TIMEOUT', 240),
    // Voice-overs (SA5b): ElevenLabs with RightAlly's key (app studio_voice).
    'voice' => env('STUDIO_VOICE', 'elevenlabs'), // elevenlabs | fake (tests)
    'daily_reels' => (int) env('STUDIO_DAILY_REELS', 10), // Reels and tours one person may make a day
    // public or unlisted once the Google project passes YouTube's API audit (until then YouTube keeps uploads private).
    // YouTube ads (specs/ads/05) need public or unlisted videos.
    'youtube_privacy' => env('STUDIO_YOUTUBE_PRIVACY', 'private'),
];
