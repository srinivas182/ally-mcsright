<?php

declare(strict_types=1);

namespace Modules\Listings\Sync;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\Listing;

/**
 * Cross-MLS de-duplication (SR1, C03): when a home is on two MLSs (common at MLS borders), searches show it once.
 *
 * The address key is the street number, the street name with common words normalised (Street → st, North → n …),
 * the unit and the 5-digit ZIP. Among on-market copies with the same key in different MLSs, the one shown is the
 * copy with the most photos, then the most recent update; the others point at it. A brokerage licensed only for
 * the other MLS still sees that MLS's copy (ListingSearch hides a copy only when the shown one is licensed too).
 */
final class Duplicates
{
    private const WORDS = ['street' => 'st', 'avenue' => 'ave', 'road' => 'rd', 'drive' => 'dr', 'lane' => 'ln', 'court' => 'ct', 'boulevard' => 'blvd', 'circle' => 'cir',
        'place' => 'pl', 'parkway' => 'pkwy', 'highway' => 'hwy', 'terrace' => 'ter', 'trail' => 'trl', 'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
        'northeast' => 'ne', 'northwest' => 'nw', 'southeast' => 'se', 'southwest' => 'sw'];

    /** The normalised address key, or null when the address is too incomplete to match safely. */
    public static function key(?string $number, ?string $street, ?string $unit, ?string $zip): ?string
    {
        $zip5 = substr(preg_replace('/\D/', '', (string) $zip) ?? '', 0, 5);
        $num = strtolower(trim((string) $number));
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) $street), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($num === '' || $words === [] || strlen($zip5) !== 5) {
            return null;
        }
        $words = array_map(fn (string $w): string => self::WORDS[$w] ?? $w, $words);
        $u = preg_replace('/^(apt|unit|ste|suite|#)\s*/', '', strtolower(trim((string) $unit))) ?? '';

        return mb_substr($num.'|'.implode(' ', $words).'|'.preg_replace('/[^a-z0-9]/', '', $u).'|'.$zip5, 0, 140);
    }

    /**
     * Refreshes keys and duplicate links for these listings and any copies of them. Returns every listing whose link
     * may have changed (for the search index).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function refresh(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $db = DB::connection(Connections::listings());
        $keys = [];
        foreach (Listing::query()->whereIn('id', $ids)->get(['id', 'street_number', 'street_name', 'unit_number', 'postal_code', 'address_key']) as $l) {
            $key = self::key($l->street_number, $l->street_name, $l->unit_number, $l->postal_code);
            if ($key !== $l->address_key) {
                $db->table('listings')->where('id', $l->id)->update(['address_key' => $key]);
            }
            if ($l->address_key !== null) {
                $keys[$l->address_key] = true; // its old group may need a new shown copy
            }
            if ($key !== null) {
                $keys[$key] = true;
            }
        }
        $touched = $ids;
        foreach (array_keys($keys) as $key) {
            $touched = [...$touched, ...$this->group((string) $key)];
        }

        return array_values(array_unique($touched));
    }

    /** @return list<int> the listings in the group */
    private function group(string $key): array
    {
        $db = DB::connection(Connections::listings());
        $copies = $db->table('listings')->where('address_key', $key)->whereNull('off_market_at')
            ->orderByDesc('photos_count')->orderByDesc('modification_timestamp')->orderBy('id')->get(['id', 'mls_source_id']);
        $db->table('listings')->where('address_key', $key)->whereNotNull('off_market_at')->update(['duplicate_of_id' => null, 'duplicate_of_source_id' => null]);
        $ids = array_values($copies->pluck('id')->map(fn ($v): int => (int) $v)->all());
        if ($copies->pluck('mls_source_id')->unique()->count() < 2) {
            $db->table('listings')->where('address_key', $key)->update(['duplicate_of_id' => null, 'duplicate_of_source_id' => null]);

            return $ids;
        }
        $shown = $copies->first();
        if ($shown === null) {
            return $ids;
        }
        foreach ($copies as $c) {
            $same = (int) $c->id === (int) $shown->id || (int) $c->mls_source_id === (int) $shown->mls_source_id; // two copies in one MLS are the MLS's own business
            $db->table('listings')->where('id', $c->id)->update($same ? ['duplicate_of_id' => null, 'duplicate_of_source_id' => null]
                : ['duplicate_of_id' => $shown->id, 'duplicate_of_source_id' => $shown->mls_source_id]);
        }

        return $ids;
    }
}
