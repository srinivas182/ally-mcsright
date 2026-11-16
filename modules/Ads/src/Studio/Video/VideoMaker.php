<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Video;

/** Turns still frames into a 9:16 video for Reels and Shorts (specs/grow/13, SA3). */
interface VideoMaker
{
    /**
     * @param  list<string>  $frames  PNG files at the video's size (1080x1920 for Reels, 1920x1080 for tours), in order
     * @param  list<float>  $seconds  how long each frame shows
     * @param  string|null  $music  an audio file, or null for a quiet track
     * @return string the MP4 file written (H.264, AAC, fast start)
     */
    public function make(array $frames, array $seconds, ?string $music, string $out, int $width = 1080, int $height = 1920): string;
}
