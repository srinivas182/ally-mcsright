<?php

declare(strict_types=1);

namespace Modules\Listings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Listings\Access\MlsAccess;

/**
 * A listing, normalised to the RESO Data Dictionary (shared per MLS). Read for display only through
 * ListingPresenter, and only for MLSs the brokerage is licensed for (scopeVisibleTo).
 *
 * @property int $id
 * @property string $ulid
 * @property int $mls_source_id
 * @property string $listing_key
 * @property string|null $listing_id
 * @property string|null $standard_status
 * @property string|null $property_type
 * @property string|null $property_sub_type
 * @property int|null $list_price_cents
 * @property int|null $original_list_price_cents
 * @property int|null $close_price_cents
 * @property CarbonImmutable|null $close_date
 * @property int|null $beds_total
 * @property int|null $baths_full
 * @property int|null $baths_half
 * @property int|null $living_area_sqft
 * @property int|null $lot_size_sqft
 * @property int|null $year_built
 * @property string|null $street_number
 * @property string|null $street_name
 * @property string|null $unit_number
 * @property string|null $city
 * @property string|null $state_or_province
 * @property string|null $postal_code
 * @property string|null $county
 * @property string|null $subdivision
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $public_remarks
 * @property string|null $list_agent_mls_id
 * @property string|null $list_office_mls_id
 * @property string|null $list_office_name
 * @property string|null $list_agent_name
 * @property string|null $virtual_tour_url_unbranded
 * @property int $photos_count
 * @property CarbonImmutable|null $on_market_date
 * @property int|null $days_on_market
 * @property CarbonImmutable|null $modification_timestamp
 * @property CarbonImmutable|null $off_market_at
 * @property bool $internet_display_allowed
 * @property bool $address_display_allowed
 * @property bool $avm_allowed
 * @property bool $comments_allowed
 * @property string|null $cover_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $price_changed_at
 * @property MlsSource $source
 */
final class Listing extends Model
{
    use HasPublicUlid;

    public const ON_MARKET = ['Active', 'Active Under Contract', 'Coming Soon', 'Pending'];

    protected $table = 'listings';

    protected $guarded = ['id', 'ulid'];

    public function getConnectionName(): ?string
    {
        return Connections::listings() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['close_date' => 'immutable_date', 'on_market_date' => 'immutable_date', 'listing_contract_date' => 'immutable_date',
            'modification_timestamp' => 'immutable_datetime', 'photos_change_timestamp' => 'immutable_datetime', 'off_market_at' => 'immutable_datetime', 'price_changed_at' => 'immutable_datetime',
            'internet_display_allowed' => 'boolean', 'address_display_allowed' => 'boolean', 'avm_allowed' => 'boolean', 'comments_allowed' => 'boolean'];
    }

    /**
     * @return BelongsTo<MlsSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MlsSource::class, 'mls_source_id');
    }

    /**
     * Only listings from MLSs this brokerage is licensed for, in that scope, right now (ADR 0012).
     *
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    public function scopeVisibleTo(Builder $query, int $tenantId, string $scope = 'idx'): Builder
    {
        return $query->whereIn('mls_source_id', app(MlsAccess::class)->licensedSources($tenantId, $scope));
    }

    public function address(): string
    {
        return trim(implode(' ', array_filter([$this->street_number, $this->street_name, $this->unit_number !== null ? '#'.$this->unit_number : null])));
    }
}
