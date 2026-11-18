<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Video;

use Modules\Ads\Studio\Render\StudioRenderer;

/**
 * Branded video intros and outros (MK3, C22, specs/grow/13). When the agent turns on "Start and end my videos with
 * my brand" in their brand kit, every video (Reels, YouTube tours, List-IQ listing videos) opens with a short intro
 * (logo, name, brokerage, on the brand colours) and ends with an outro (name, phone, email, licence line and the
 * brokerage disclaimer), at the video's own size. Drawn on RightAlly's renderer; no extra cost.
 */
final class Bumpers
{
    public const INTRO = 2.5;

    public const OUTRO = 3.5;

    /**
     * Adds the intro and outro frames when the kit asks for them.
     *
     * @param  array<string, mixed>  $kit  BrandKits::forUser()
     * @param  list<string>  $frames
     * @param  list<float>  $seconds
     * @return array{0: list<string>, 1: list<float>, 2: bool} frames, seconds, whether bumpers were added
     */
    public static function wrap(array $kit, array $frames, array $seconds, string $dir, int $width, int $height): array
    {
        if ((($kit['details']['video_bumpers'] ?? '') !== 'yes') || $frames === []) {
            return [$frames, $seconds, false];
        }
        $renderer = app(StudioRenderer::class);
        file_put_contents($intro = $dir.'/00-intro.png', $renderer->render(self::intro($kit, $width, $height), $width, $height));
        file_put_contents($outro = $dir.'/99-outro.png', $renderer->render(self::outro($kit, $width, $height), $width, $height));

        return [[$intro, ...$frames, $outro], [self::INTRO, ...$seconds, self::OUTRO], true];
    }

    /** @param  array<string, mixed>  $kit */
    public static function intro(array $kit, int $width, int $height): string
    {
        $d = (array) $kit['details'];
        $logo = $kit['logo'] ?? null;

        return self::page($kit, $width, $height, ($logo !== null ? '<img src="'.self::e((string) $logo).'" alt="" style="max-width:60%;max-height:22%;object-fit:contain">' : '')
            .'<h1>'.self::e((string) ($d['display_name'] ?? '')).'</h1><p>'.self::e((string) ($kit['brokerage'] ?? '')).'</p>'
            .(($d['tagline'] ?? '') !== '' ? '<p class="small">'.self::e((string) $d['tagline']).'</p>' : ''));
    }

    /** @param  array<string, mixed>  $kit */
    public static function outro(array $kit, int $width, int $height): string
    {
        $d = (array) $kit['details'];
        $lines = array_filter([(string) ($d['phone'] ?? ''), (string) ($d['email'] ?? '')]);

        return self::page($kit, $width, $height, '<p>Ask me anything</p><h1>'.self::e((string) ($d['display_name'] ?? '')).'</h1>'
            .implode('', array_map(fn (string $l): string => '<p>'.self::e($l).'</p>', $lines)).'<p class="small">'.self::e((string) ($kit['brokerage'] ?? '')).'</p>'
            .(($d['licence_line'] ?? '') !== '' ? '<p class="small">'.self::e((string) $d['licence_line']).'</p>' : '')
            .(($d['disclaimer'] ?? '') !== '' ? '<p class="tiny">'.self::e((string) $d['disclaimer']).'</p>' : ''));
    }

    /** @param  array<string, mixed>  $kit */
    private static function page(array $kit, int $width, int $height, string $inner): string
    {
        $c = (array) $kit['colors'];
        $f = (array) $kit['fonts'];
        $scale = min($width, $height) / 1080;
        $px = fn (float $n): string => round($n * $scale).'px';

        return '<!doctype html><html><head><meta charset="utf-8"><style>*{margin:0;box-sizing:border-box}body{width:'.$width.'px;height:'.$height.'px;display:flex;flex-direction:column;'
            .'align-items:center;justify-content:center;gap:'.$px(28).';text-align:center;padding:'.$px(80).';background:'.self::color((string) ($c['primary'] ?? '#14213d')).';color:'
            .self::color((string) ($c['paper'] ?? '#ffffff')).';font-family:"'.self::e((string) ($f['body'] ?? 'Inter')).'",Helvetica,Arial,sans-serif}'
            .'h1{font-family:"'.self::e((string) ($f['heading'] ?? 'Playfair Display')).'",Georgia,serif;font-size:'.$px(88).';font-weight:700}p{font-size:'.$px(48).'}'
            .'.small{font-size:'.$px(34).';opacity:.9}.tiny{font-size:'.$px(24).';opacity:.8;max-width:90%}</style></head><body>'.$inner.'</body></html>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }

    private static function color(string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1 ? $c : '#14213d';
    }
}
