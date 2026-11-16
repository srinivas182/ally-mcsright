<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Video;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The Reel look with ffmpeg on the render workers: each frame drifts with a slow camera move (in, then out, in
 * turn), frames cross-dissolve, and the music fades out at the end. Frames are scaled up before the move so the
 * motion stays smooth. The output is H.264 and AAC with the index at the front, as Instagram, Facebook and YouTube
 * expect.
 */
final class FfmpegVideoMaker implements VideoMaker
{
    public const FPS = 30;

    public const CROSSFADE = 0.6;

    /** How far the camera moves over one frame (7%). */
    private const ZOOM = 0.07;

    public function make(array $frames, array $seconds, ?string $music, string $out): string
    {
        if ($frames === [] || count($frames) !== count($seconds)) {
            throw new RuntimeException('A video needs frames and how long each shows.');
        }
        $total = array_sum($seconds) - self::CROSSFADE * (count($frames) - 1);
        $args = [(string) config('studio.ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error'];
        foreach ($frames as $f) {
            $args = [...$args, '-i', $f];
        }
        $args = [...$args, ...($music !== null ? ['-stream_loop', '-1', '-i', $music] : ['-f', 'lavfi', '-t', (string) ceil($total), '-i', 'anullsrc=r=44100:cl=stereo'])];
        $parts = [];
        foreach ($frames as $i => $_) {
            $n = (int) round($seconds[$i] * self::FPS);
            $z = $i % 2 === 0 ? '1+'.self::ZOOM.'*on/'.$n : (1 + self::ZOOM).'-'.self::ZOOM.'*on/'.$n;
            $parts[] = "[{$i}:v]scale=2160:3840:flags=lanczos,zoompan=z='{$z}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$n}:s=1080x1920:fps=".self::FPS.",setsar=1,format=yuv420p[v{$i}]";
        }
        $prev = 'v0';
        $offset = 0.0;
        for ($i = 1; $i < count($frames); $i++) {
            $offset += $seconds[$i - 1] - self::CROSSFADE;
            $parts[] = "[{$prev}][v{$i}]xfade=transition=fade:duration=".self::CROSSFADE.':offset='.number_format($offset, 2, '.', '')."[x{$i}]";
            $prev = "x{$i}";
        }
        $audio = count($frames);
        $parts[] = "[{$audio}:a]atrim=0:".number_format($total, 2, '.', '').',afade=t=in:d=0.5,afade=t=out:st='.number_format(max(0, $total - 1.5), 2, '.', '').':d=1.5,loudnorm=I=-16:TP=-1.5[a]';
        $args = [...$args, '-filter_complex', implode(';', $parts), '-map', "[{$prev}]", '-map', '[a]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-pix_fmt', 'yuv420p', '-r', (string) self::FPS, '-c:a', 'aac', '-b:a', '160k', '-ar', '44100', '-t', number_format($total, 2, '.', ''), '-movflags', '+faststart', $out];
        $p = new Process($args);
        $p->setTimeout((float) config('studio.video_timeout', 240));
        $p->run();
        if (! $p->isSuccessful() || ! is_file($out) || filesize($out) < 1000) {
            throw new RuntimeException('ffmpeg could not make the video: '.mb_substr($p->getErrorOutput(), 0, 300));
        }

        return $out;
    }
}
