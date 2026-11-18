<?php

declare(strict_types=1);

namespace Modules\ListIq;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\TenantContext;
use Modules\ListIq\Models\Prep;
use Throwable;

/**
 * 3D and 360-degree tours and video tours on a listing (MD1, C13, specs/listings/03).
 *
 * The agent pastes the tour's link from Matterport, iGUIDE, CubiCasa, Kuula, Zillow 3D Home, YouTube or Vimeo, or
 * (with the brokerage's or HQ's Matterport account connected in Outside services → Floor plans and tours) picks one
 * of their Matterport spaces. Only those sites are accepted; the tour plays on the listing page and its unbranded
 * link goes in the MLS package (MLS virtual-tour field).
 */
final class Tours
{
    public const PROVIDERS = ['matterport' => 'Matterport', 'iguide' => 'iGUIDE', 'cubicasa' => 'CubiCasa', 'kuula' => 'Kuula', 'zillow' => 'Zillow 3D Home',
        'youtube' => 'YouTube', 'vimeo' => 'Vimeo'];

    /**
     * What a link is: the provider, the address to play it in the page (null when it only opens in a new tab), and the
     * clean link.
     *
     * @return array{provider: string, embed: string|null, link: string}|null
     */
    public static function read(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $q);
        $is = fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain);

        return match (true) {
            $is('matterport.com') && is_string($q['m'] ?? null) && preg_match('/^[A-Za-z0-9]{8,20}$/', $q['m']) === 1 => ['provider' => 'matterport',
                'embed' => 'https://my.matterport.com/show/?m='.$q['m'].'&play=1&brand=0', 'link' => 'https://my.matterport.com/show/?m='.$q['m'].'&brand=0'],
            $is('youriguide.com') && preg_match('#^/([A-Za-z0-9_\-]{3,80})/?#', $path, $m) === 1 => ['provider' => 'iguide',
                'embed' => 'https://youriguide.com/'.$m[1].'/', 'link' => 'https://unbranded.youriguide.com/'.$m[1].'/'],
            $is('cubi.casa') => ['provider' => 'cubicasa', 'embed' => null, 'link' => $url],
            $is('kuula.co') && preg_match('#^/share/(collection/)?([A-Za-z0-9]{4,20})#', $path, $m) === 1 => ['provider' => 'kuula',
                'embed' => 'https://kuula.co/share/'.$m[1].$m[2].'?fs=1&vr=0&thumbs=1&info=0&logo=-1', 'link' => 'https://kuula.co/share/'.$m[1].$m[2]],
            $is('zillow.com') && str_starts_with($path, '/view-imx/') => ['provider' => 'zillow', 'embed' => $url, 'link' => $url],
            ($is('youtube.com') && is_string($q['v'] ?? null) && preg_match('/^[A-Za-z0-9_\-]{11}$/', $q['v']) === 1)
                || ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_\-]{11})$#', $path) === 1) => (function () use ($q, $path): array {
                    $id = is_string($q['v'] ?? null) ? $q['v'] : ltrim($path, '/');

                    return ['provider' => 'youtube', 'embed' => 'https://www.youtube-nocookie.com/embed/'.$id, 'link' => 'https://www.youtube.com/watch?v='.$id];
                })(),
            $is('vimeo.com') && preg_match('#^/(\d{6,12})#', $path, $m) === 1 => ['provider' => 'vimeo', 'embed' => 'https://player.vimeo.com/video/'.$m[1], 'link' => 'https://vimeo.com/'.$m[1]],
            default => null,
        };
    }

    /** Saves (or, with an empty link, removes) the listing's tour. */
    public function set(Prep $prep, ?string $url): void
    {
        if ($url === null || trim($url) === '') {
            $prep->forceFill(['tour_url' => null, 'tour_provider' => null])->save();

            return;
        }
        $read = self::read($url);
        if ($read === null) {
            throw ValidationException::withMessages(['tour' => 'Paste the https link of a tour from Matterport, iGUIDE, CubiCasa, Kuula, Zillow 3D Home, YouTube or Vimeo.']);
        }
        $prep->forceFill(['tour_url' => mb_substr($read['link'], 0, 500), 'tour_provider' => $read['provider']])->save();
    }

    /**
     * The Matterport spaces in the connected account (newest first), when Matterport is the floor-plan and tour
     * service.
     *
     * @return list<array{id: string, name: string}>|null null when Matterport is not connected
     */
    public function matterport(): ?array
    {
        $service = app(OutsideServices::class)->active('floor_plans', app(TenantContext::class)->id());
        if ($service === null || $service['provider'] !== 'matterport') {
            return null;
        }
        try {
            $r = Http::timeout(10)->acceptJson()->withBasicAuth((string) ($service['keys']['token_id'] ?? ''), (string) ($service['keys']['token_secret'] ?? ''))
                ->post('https://api.matterport.com/api/models/graph', ['query' => '{ models(pageSize: 50, sortBy: [{field: created, order: desc}]) { results { id name } } }']);
            if ($r->failed()) {
                return [];
            }

            return array_values(array_filter(array_map(fn ($m): ?array => is_array($m) && is_string($m['id'] ?? null) ? ['id' => $m['id'], 'name' => (string) ($m['name'] ?? $m['id'])] : null,
                (array) $r->json('data.models.results', []))));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
