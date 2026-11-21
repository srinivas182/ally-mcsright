<?php

declare(strict_types=1);

namespace Modules\Listings\Sync;

use Carbon\CarbonImmutable;

/**
 * RESO Data Dictionary 2.x record → listing columns. Per-MLS overrides live in modules/Listings/mappings/{key}.php
 * (column => RESO field name or a callable). Unmapped fields are never lost: the raw record is kept in
 * listing_payloads.
 */
final class ResoMapper
{
    private const FIELDS = [
        'listing_key' => 'ListingKey', 'listing_id' => 'ListingId', 'standard_status' => 'StandardStatus', 'mls_status' => 'MlsStatus',
        'property_type' => 'PropertyType', 'property_sub_type' => 'PropertySubType', 'beds_total' => 'BedroomsTotal', 'baths_full' => 'BathroomsFull',
        'baths_half' => 'BathroomsHalf', 'living_area_sqft' => 'LivingArea', 'year_built' => 'YearBuilt', 'street_number' => 'StreetNumber',
        'street_name' => 'StreetName', 'unit_number' => 'UnitNumber', 'city' => 'City', 'state_or_province' => 'StateOrProvince', 'postal_code' => 'PostalCode',
        'county' => 'CountyOrParish', 'subdivision' => 'SubdivisionName', 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'public_remarks' => 'PublicRemarks',
        'list_agent_mls_id' => 'ListAgentMlsId', 'list_office_mls_id' => 'ListOfficeMlsId', 'list_office_name' => 'ListOfficeName', 'list_agent_name' => 'ListAgentFullName',
        'co_list_agent_mls_id' => 'CoListAgentMlsId', 'buyer_agent_mls_id' => 'BuyerAgentMlsId', 'buyer_office_mls_id' => 'BuyerOfficeMlsId',
        'virtual_tour_url_unbranded' => 'VirtualTourURLUnbranded', 'photos_count' => 'PhotosCount', 'days_on_market' => 'DaysOnMarket',
    ];

    private const MONEY = ['list_price_cents' => 'ListPrice', 'original_list_price_cents' => 'OriginalListPrice', 'close_price_cents' => 'ClosePrice'];

    private const DATES = ['close_date' => 'CloseDate', 'listing_contract_date' => 'ListingContractDate', 'on_market_date' => 'OnMarketDate'];

    private const TIMES = ['modification_timestamp' => 'ModificationTimestamp', 'photos_change_timestamp' => 'PhotosChangeTimestamp', 'off_market_at' => 'OffMarketTimestamp'];

    private const FLAGS = ['internet_display_allowed' => 'InternetEntireListingDisplayYN', 'address_display_allowed' => 'InternetAddressDisplayYN',
        'avm_allowed' => 'InternetAutomatedValuationDisplayYN', 'comments_allowed' => 'InternetConsumerCommentYN'];

    private const LIMITS = ['listing_key' => 60, 'listing_id' => 40, 'standard_status' => 30, 'mls_status' => 40, 'property_type' => 40, 'property_sub_type' => 60,
        'street_number' => 20, 'street_name' => 100, 'unit_number' => 20, 'city' => 80, 'state_or_province' => 2, 'postal_code' => 10, 'county' => 60, 'subdivision' => 100,
        'list_agent_mls_id' => 30, 'list_office_mls_id' => 30, 'list_office_name' => 120, 'list_agent_name' => 120, 'co_list_agent_mls_id' => 30, 'buyer_agent_mls_id' => 30,
        'buyer_office_mls_id' => 30, 'virtual_tour_url_unbranded' => 500];

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function map(array $r, string $sourceKey): array
    {
        $out = [];
        foreach (self::FIELDS as $col => $field) {
            $v = $r[$field] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                $v = isset(self::LIMITS[$col]) ? mb_substr($v, 0, self::LIMITS[$col]) : mb_substr($v, 0, 10000);
                $v = $v === '' ? null : $v;
            }
            $out[$col] = is_scalar($v) ? $v : null;
        }
        $photos = is_numeric($r['PhotosCount'] ?? null) ? max(0, (int) $r['PhotosCount']) : 0;
        foreach (['beds_total', 'baths_full', 'baths_half', 'year_built', 'days_on_market', 'living_area_sqft'] as $int) {
            $v = $out[$int] ?? null;
            $out[$int] = is_numeric($v) ? max(0, (int) round((float) $v)) : null;
        }
        foreach (['beds_total', 'baths_full', 'baths_half'] as $tiny) {
            $v = $out[$tiny] ?? null;
            $out[$tiny] = is_int($v) ? min(255, $v) : null;
        }
        $out['photos_count'] = min(65535, $photos);
        if ($photos === 0 && is_array($r['Media'] ?? null)) {
            $out['photos_count'] = min(65535, count($r['Media']));
        }
        $out['lot_size_sqft'] = is_numeric($r['LotSizeSquareFeet'] ?? null) ? (int) round((float) $r['LotSizeSquareFeet'])
            : (is_numeric($r['LotSizeAcres'] ?? null) ? (int) round((float) $r['LotSizeAcres'] * 43560) : null);
        foreach (self::MONEY as $col => $field) {
            $out[$col] = is_numeric($r[$field] ?? null) && (float) $r[$field] >= 0 ? (int) round((float) $r[$field] * 100) : null;
        }
        foreach (self::DATES as $col => $field) {
            $out[$col] = self::date($r[$field] ?? null, 'Y-m-d');
        }
        foreach (self::TIMES as $col => $field) {
            $out[$col] = self::date($r[$field] ?? null, 'Y-m-d H:i:s');
        }
        foreach (self::FLAGS as $col => $field) {
            $out[$col] = ($r[$field] ?? true) !== false;
        }
        // IDX10: home facts for plain-words search; null when the MLS does not say.
        $yn = static fn (mixed $v): ?bool => is_bool($v) ? $v : (is_string($v) && in_array(strtolower(trim($v)), ['y', 'yes', 'true', '1'], true) ? true
            : (is_string($v) && in_array(strtolower(trim($v)), ['n', 'no', 'false', '0'], true) ? false : (is_int($v) ? $v > 0 : null)));
        $out['pool'] = $yn($r['PoolPrivateYN'] ?? null);
        $out['waterfront'] = $yn($r['WaterfrontYN'] ?? null);
        $out['new_construction'] = $yn($r['NewConstructionYN'] ?? null);
        $out['garage_spaces'] = is_numeric($r['GarageSpaces'] ?? null) ? min(20, max(0, (int) floor((float) $r['GarageSpaces']))) : null;
        $state = $out['state_or_province'] ?? null;
        $out['state_or_province'] = is_string($state) ? strtoupper($state) : null;
        foreach (['latitude', 'longitude'] as $coord) {
            $v = $out[$coord] ?? null;
            $out[$coord] = is_numeric($v) && abs((float) $v) <= 180 ? round((float) $v, 7) : null;
        }

        $overrides = base_path('modules/Listings/mappings/'.preg_replace('/[^a-z0-9_-]/', '', strtolower($sourceKey)).'.php');
        if (is_file($overrides)) {
            foreach ((array) require $overrides as $col => $field) {
                $out[$col] = is_callable($field) ? $field($r) : ($r[$field] ?? null);
            }
        }

        return $out;
    }

    private static function date(mixed $v, string $format): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v)->utc()->format($format);
        } catch (\Throwable) {
            return null;
        }
    }
}
