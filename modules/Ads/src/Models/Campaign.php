<?php

declare(strict_types=1);

namespace Modules\Ads\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One listing ad (specs/ads/01).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property int $connection_id
 * @property string $channel
 * @property string $ad_account_id
 * @property string $page_id
 * @property string $listing_ulid
 * @property string $listing_label
 * @property string $headline
 * @property string $body
 * @property string $image_url
 * @property string $link
 * @property string $latitude
 * @property string $longitude
 * @property int $radius_miles
 * @property int $daily_budget_cents
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $status
 * @property string|null $error
 * @property array<string, string>|null $meta_ids
 * @property int $spend_cents
 * @property int $impressions
 * @property int $clicks
 * @property int $leads
 * @property CarbonImmutable|null $results_at
 * @property CarbonImmutable $created_at
 */
final class Campaign extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'ads_campaigns';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** The most it can spend, in cents (daily budget × days). */
    public function maxSpendCents(): int
    {
        return $this->daily_budget_cents * ((int) $this->starts_on->diffInDays($this->ends_on) + 1);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta_ids' => 'array', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'results_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
