<?php

declare(strict_types=1);

namespace Modules\Ads;

use Modules\Billing\Credits\RateCard;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;

/**
 * RightAlly Boost (specs/ads/02): ads paid with RightAlly credits, run from RightAlly's own Meta ad account and Page.
 * HQ sets the account, Page, access token and margin on the "RightAlly Boost" card (HQ → Connect setup); the margin
 * defaults to 15% and can be changed at any time (each boost keeps the margin it was bought with).
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
    private function keys(): array
    {
        $app = $this->catalog->find('rightally_boost');

        return $app !== null ? $this->registry->secrets($app) : [];
    }
}
