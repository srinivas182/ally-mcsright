<?php

declare(strict_types=1);

namespace Modules\Listings\Display;

use Carbon\CarbonImmutable;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\RulesProfile;

/**
 * The only way a listing reaches a page (specs/websites/02): applies the MLS's rules — whether it may be shown at
 * all, sold-data limits, suppressed fields, address/AVM/comments flags — and adds the required attribution,
 * disclaimer, logo, freshness notice and whether search engines may index it.
 */
final class ListingPresenter
{
    /**
     * @return array<string, mixed>|null null when the rules do not allow this listing to be shown
     */
    public static function forDisplay(Listing $listing, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();
        $source = $listing->source;
        $rules = $source->rulesProfile ?? new RulesProfile(['name' => 'Default', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
            'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720, 'index_off_market' => false]);

        if (! $listing->internet_display_allowed) {
            return null;
        }
        $sold = $listing->standard_status === 'Closed';
        $onMarket = in_array($listing->standard_status, Listing::ON_MARKET, true) && $listing->off_market_at === null;
        if ($sold && (! $rules->sold_allowed || ($listing->close_date !== null && $listing->close_date->addDays($rules->sold_delay_days)->gt($now)))) {
            return null;
        }
        if (! $sold && ! $onMarket) {
            return null;
        }

        $suppressed = $rules->suppressed_fields ?? [];
        $show = static fn (string $field): bool => ! in_array($field, $suppressed, true);
        $fields = [
            'ulid' => $listing->ulid,
            'mls_number' => $listing->listing_id,
            'status' => $listing->standard_status,
            'price' => $sold ? ($show('close_price') ? self::dollars($listing->close_price_cents) : null) : self::dollars($listing->list_price_cents),
            'original_price' => $show('original_list_price') ? self::dollars($listing->original_list_price_cents) : null,
            'beds' => $listing->beds_total,
            'baths_full' => $listing->baths_full,
            'baths_half' => $listing->baths_half,
            'sqft' => $listing->living_area_sqft,
            'lot_sqft' => $show('lot_size') ? $listing->lot_size_sqft : null,
            'year_built' => $show('year_built') ? $listing->year_built : null,
            'property_type' => $listing->property_type,
            'property_sub_type' => $listing->property_sub_type,
            'address' => $listing->address_display_allowed ? $listing->address() : null,
            'city' => $listing->city,
            'state' => $listing->state_or_province,
            'postal_code' => $listing->postal_code,
            'subdivision' => $show('subdivision') ? $listing->subdivision : null,
            'latitude' => $listing->address_display_allowed && $listing->latitude !== null ? (float) $listing->latitude : null,
            'longitude' => $listing->address_display_allowed && $listing->longitude !== null ? (float) $listing->longitude : null,
            'remarks' => $show('public_remarks') ? $listing->public_remarks : null,
            'days_on_market' => $show('days_on_market') ? $listing->days_on_market : null,
            'virtual_tour' => $show('virtual_tour') ? $listing->virtual_tour_url_unbranded : null,
            'photos' => $listing->photos_count,
            'close_date' => $sold ? $listing->close_date?->toDateString() : null,
        ];

        $stale = $source->last_success_at === null || $source->last_success_at->lt($now->subMinutes($rules->freshness_minutes));

        return $fields + [
            'attribution' => strtr($rules->attribution_template, ['{ListOfficeName}' => (string) $listing->list_office_name, '{ListAgentName}' => (string) $listing->list_agent_name,
                '{MlsName}' => $source->name]),
            'disclaimer' => $rules->disclaimer !== null ? strtr($rules->disclaimer, ['{LastUpdated}' => $source->last_success_at?->setTimezone($source->timezone)->format('m/d/Y g:i A T') ?? '']) : null,
            'logo' => $rules->logo_url,
            'allow_avm' => $listing->avm_allowed,
            'allow_comments' => $listing->comments_allowed,
            'stale' => $stale,
            // IDX5: a sample from a demo feed is marked as such and never indexed.
            'sample' => $source->is_demo,
            'indexable' => ! $source->is_demo && ($onMarket || $rules->index_off_market),
        ];
    }

    private static function dollars(?int $cents): ?int
    {
        return $cents !== null ? intdiv($cents, 100) : null;
    }
}
