<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Modules\Core\Branding\EmailSignature;

/**
 * The email signature from the brand kit (MK2, specs/crm/21): name, title, brokerage, phone, website, licence line,
 * headshot and logo in the brand colour. The HTML uses inline styles and table layout only, which every mail app
 * understands, and images by full web address.
 */
final class BrandSignature implements EmailSignature
{
    public function __construct(private readonly BrandKits $kits) {}

    public function text(User $user): string
    {
        $k = $this->kits->forUser($user);
        $d = $k['details'];

        return implode("\n", array_filter([$d['display_name'] ?? $user->name, $d['title'] ?? null, $k['brokerage'],
            implode(' · ', array_filter([$d['phone'] ?? null, $d['email'] ?? null])), $d['website'] ?? null, $d['licence_line'] ?? null],
            fn (?string $l): bool => $l !== null && trim($l) !== ''));
    }

    public function html(User $user): string
    {
        $k = $this->kits->forUser($user);
        $d = $k['details'];
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($k['colors']['primary'] ?? '')) === 1 ? (string) $k['colors']['primary'] : '#1f2937';
        $img = fn (?string $src, string $alt, int $size, string $style): string => $src !== null && $src !== ''
            ? '<img src="'.e(url($src)).'" alt="'.e($alt).'" width="'.$size.'" style="'.$style.'">' : '';
        $line = fn (?string $v, string $style = ''): string => $v !== null && trim($v) !== '' ? '<div style="'.$style.'">'.e($v).'</div>' : '';
        $phone = (string) ($d['phone'] ?? '');

        return '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#222"><tr>'
            .($k['headshot'] !== null ? '<td style="padding-right:14px;vertical-align:top">'.$img($k['headshot'], (string) ($d['display_name'] ?? ''), 72, 'border-radius:50%;display:block').'</td>' : '')
            .'<td style="vertical-align:top;border-left:3px solid '.$color.';padding-left:12px">'
            .$line($d['display_name'] ?? $user->name, 'font-size:16px;font-weight:bold;color:'.$color)
            .$line($d['title'] ?? null)
            .$line($k['brokerage'], 'font-weight:bold')
            .($phone !== '' ? '<div><a href="tel:'.e(preg_replace('/[^\d+]/', '', $phone) ?? '').'" style="color:#222;text-decoration:none">'.e($phone).'</a></div>' : '')
            .(($d['email'] ?? '') !== '' ? '<div><a href="mailto:'.e((string) $d['email']).'" style="color:'.$color.'">'.e((string) $d['email']).'</a></div>' : '')
            .(preg_match('#^https?://#i', (string) ($d['website'] ?? '')) === 1 ? '<div><a href="'.e((string) $d['website']).'" style="color:'.$color.'">'.e(preg_replace('#^https?://#', '', (string) $d['website']) ?? '').'</a></div>' : '')
            .$line($d['licence_line'] ?? null, 'font-size:12px;color:#666')
            .($k['logo'] !== null ? '<div style="margin-top:8px">'.$img($k['logo'], $k['brokerage'], 120, 'display:block;max-width:120px;height:auto').'</div>' : '')
            .'</td></tr></table>';
    }
}
