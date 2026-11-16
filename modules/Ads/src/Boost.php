<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Credits\RateCard;
use Modules\Connect\AppRegistry;
use Modules\Connect\OAuth\Providers;
use Modules\Core\Connect\AppCatalog;
use RuntimeException;

/**
 * RightAlly Boost (specs/ads/02): ads paid with RightAlly credits, run from RightAlly's own Meta ad account and Page.
 * HQ sets the account, Page, access token and margin on the "RightAlly Boost" card (HQ → Connect setup); the margin
 * defaults to 15% and can be changed at any time (each boost keeps the margin it was bought with).
 *
 * Boost on Google (specs/ads/05) runs from RightAlly's Google Ads account ("RightAlly Boost on Google" card: the
 * customer id, the manager id if any, and a refresh token for RightAlly's Google login), with the same margin.
 */
final class Boost
{
    public const DEFAULT_MARGIN_PERCENT = 15;

    public const ACTION = 'ads.boost';

    public function __construct(private readonly AppRegistry $registry, private readonly AppCatalog $catalog, private readonly RateCard $rates) {}

    public function ready(): bool
    {
        $app = $this->catalog->find('rightally_boost');

        return $app !== null && $this->registry->ready($app);
    }

    /** Boost on Google: both Boost cards, and Google Ads itself (developer token), are set up and on. */
    public function googleReady(): bool
    {
        $google = $this->catalog->find('rightally_boost_google');
        $ads = $this->catalog->find('google_ads');

        return $this->ready() && $google !== null && $this->registry->ready($google) && $ads !== null && $this->registry->setupComplete($ads);
    }

    /** @return array{customer_id: string, login_customer_id: string} */
    public function googleAccount(): array
    {
        $k = $this->googleKeys();

        return ['customer_id' => preg_replace('/\D/', '', (string) ($k['customer_id'] ?? '')) ?? '', 'login_customer_id' => preg_replace('/\D/', '', (string) ($k['login_customer_id'] ?? '')) ?? ''];
    }

    /** An access token for RightAlly's Google Ads login, from its refresh token (kept encrypted for 50 minutes). */
    public function googleToken(): string
    {
        $cached = Cache::get('ads:boost:google-token');
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                // fall through and get a new one
            }
        }
        $google = Providers::get('google');
        $r = Http::asForm()->timeout(20)->post($google['token'], ['grant_type' => 'refresh_token', 'refresh_token' => (string) ($this->googleKeys()['refresh_token'] ?? ''),
            'client_id' => $google['client_id'], 'client_secret' => $google['client_secret']]);
        $token = $r->json('access_token');
        if (! $r->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('RightAlly Boost can\'t sign in to Google Ads. HQ needs to renew its Google sign-in.');
        }
        Cache::put('ads:boost:google-token', Crypt::encryptString($token), now()->addMinutes(50));

        return $token;
    }

    /** @return array{ad_account_id: string, page_id: string, access_token: string} */
    public function account(): array
    {
        $k = $this->keys();

        return ['ad_account_id' => (string) ($k['ad_account_id'] ?? ''), 'page_id' => (string) ($k['page_id'] ?? ''), 'access_token' => (string) ($k['access_token'] ?? '')];
    }

    /** RightAlly's margin, in basis points (1500 = 15%), between 0% and 100%. */
    public function marginBps(): int
    {
        $raw = trim((string) ($this->keys()['margin_percent'] ?? ''));
        $percent = is_numeric($raw) ? (float) $raw : self::DEFAULT_MARGIN_PERCENT;

        return (int) round(max(0, min(100, $percent)) * 100);
    }

    /** Credits for an amount of ad spend (cents), with the margin; rounded up. */
    public function credits(int $spendCents, int $marginBps): int
    {
        $value = max(1, $this->rates->creditValueMicrocents()); // what one credit is worth, in millionths of a cent

        return (int) ceil($spendCents * (10_000 + $marginBps) / 10_000 * 1_000_000 / $value);
    }

    /** @return array<string, string> */
    private function googleKeys(): array
    {
        $app = $this->catalog->find('rightally_boost_google');

        return $app !== null ? $this->registry->secrets($app) : [];
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        $app = $this->catalog->find('rightally_boost');

        return $app !== null ? $this->registry->secrets($app) : [];
    }
}
