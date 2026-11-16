<?php

declare(strict_types=1);

namespace Modules\Ads;

use Modules\Ads\Google\GoogleAdsApi;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Models\Campaign;

/** The network an ad runs on, by its channel (specs/ads/05). */
final class Networks
{
    public static function for(Campaign $c): AdNetwork
    {
        return $c->channel === 'google' ? app(GoogleAdsApi::class) : app(MetaApi::class);
    }
}
