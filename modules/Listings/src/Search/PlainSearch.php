<?php

declare(strict_types=1);

namespace Modules\Listings\Search;

/**
 * Plain-words home search in English and Spanish (specs/websites/17): "3 bed homes under 500k in Austin" or
 * "casa de 3 recámaras hasta 400 mil en Dallas" becomes the same filters the search form uses. Rules only, with no
 * AI and no cost, so it is instant and predictable; anything it cannot filter on (a pool, a view) is listed back to
 * the visitor honestly instead of being guessed. Fair Housing: it never reads words about people (schools "for
 * families", neighbourhood make-up); only the home's facts are filters.
 */
final class PlainSearch
{
    private const NUMBERS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6];

    private const BEDS = 'beds?|bedrooms?|bd|br|bdrm|rec[aá]maras?|habitaciones?|cuartos?|dormitorios?';

    private const BATHS = 'baths?|bathrooms?|ba|ba[nñ]os?';

    /** Words for features we cannot filter on yet (shown back so nothing is silently ignored). */
    private const FEATURES = [
        'pool' => 'pool', 'piscina' => 'pool', 'alberca' => 'pool', 'garage' => 'garage', 'garaje' => 'garage', 'cochera' => 'garage',
        'waterfront' => 'waterfront', 'lake' => 'waterfront', 'lago' => 'waterfront', 'view' => 'view', 'vista' => 'view',
        'yard' => 'yard', 'patio' => 'yard', 'jard[ií]n' => 'yard', 'basement' => 'basement', 's[oó]tano' => 'basement',
        'condo' => 'condo or townhome', 'condominio' => 'condo or townhome', 'townhouse' => 'condo or townhome', 'townhome' => 'condo or townhome', 'acreage' => 'acreage', 'acres?' => 'acreage', 'new construction' => 'new construction', 'nueva construcci[oó]n' => 'new construction',
    ];

    /** Plain words to RESO property types. */
    private const TYPES = [
        'land|lot|lots|terreno|lote' => 'Land', 'farm|ranch|rancho|granja' => 'Farm', 'rent|rental|lease|renta|alquiler|arriendo' => 'Residential Lease',
        'duplex|multi-?family|multifamiliar|d[uú]plex' => 'Residential Income', 'commercial|comercial' => 'Commercial Sale',
    ];

    /**
     * True when the words look like a sentence to read rather than a place name to match.
     */
    public static function looksPlain(string $text): bool
    {
        $t = mb_strtolower($text);

        return preg_match('/\d\s*\+?\s*('.self::BEDS.'|'.self::BATHS.')\b|\b(under|below|less than|over|above|more than|between|max|min|menos de|hasta|m[aá]s de|desde|entre)\b|\d\s*(k|m|mil)\b|\$/u', $t) === 1
            || preg_match('/\b('.implode('|', array_keys(self::NUMBERS)).')\s+('.self::BEDS.')\b/u', $t) === 1;
    }

    /**
     * @return array{filters: array<string, string>, understood: list<string>, not_filtered: list<string>, language: string}
     */
    public static function parse(string $text): array
    {
        $t = ' '.mb_strtolower(trim(mb_substr($text, 0, 200))).' ';
        $spanish = preg_match('/\b(casa|casas|rec[aá]maras?|habitaciones|ba[nñ]os?|menos de|hasta|m[aá]s de|entre|en venta|cerca de|terreno|mil)\b/u', $t) === 1;
        $f = [];
        $understood = [];
        $t = preg_replace_callback('/\b('.implode('|', array_keys(self::NUMBERS)).')\b(?=\s*('.self::BEDS.'|'.self::BATHS.')\b)/u',
            fn (array $m): string => (string) self::NUMBERS[$m[1]], $t) ?? $t;

        $rent = preg_match('/\b(rent|rental|lease|renta|alquiler|arriendo)\b/u', $t) === 1;
        $money = '\$?\s*(\d[\d,\.]*)\s*(millones|million|thousand|mil|k|m)?(?![a-zà-ÿ])';
        $between = '/\b(?:between|entre|from|de)\s+'.$money.'\s*(?:and|y|to|a|-)\s*'.$money.'/u';
        if (preg_match($between, $t, $m) === 1) {
            $f['min_price'] = (string) self::amount($m[1], $m[2], $rent);
            $f['max_price'] = (string) self::amount($m[3], $m[4] ?? '', $rent);
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/'.$money.'\s*-\s*'.$money.'/u', $t, $m) === 1 && self::amount($m[1], $m[2], $rent) >= 10000) {
            $f['min_price'] = (string) self::amount($m[1], $m[2], $rent);
            $f['max_price'] = (string) self::amount($m[3], $m[4] ?? '', $rent);
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/\b(?:under|below|less than|max|up to|no more than|menos de|hasta|m[aá]ximo)\s+'.$money.'/u', $t, $m) === 1) {
            $f['max_price'] = (string) self::amount($m[1], $m[2] ?? '', $rent);
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/\b(?:over|above|more than|at least|min|m[aá]s de|desde|m[ií]nimo)\s+'.$money.'/u', $t, $m) === 1 && ! isset($f['min_price'])) {
            $amount = self::amount($m[1], $m[2] ?? '', $rent);
            if ($amount >= 10000) {
                $f['min_price'] = (string) $amount;
                $t = str_replace($m[0], ' ', $t);
            }
        }
        foreach (['min_price', 'max_price'] as $k) {
            if (isset($f[$k]) && ((int) $f[$k] < ($rent ? 100 : 1000) || (int) $f[$k] > 100_000_000)) {
                unset($f[$k]);
            }
        }
        if (isset($f['min_price'], $f['max_price']) && (int) $f['min_price'] > (int) $f['max_price']) {
            [$f['min_price'], $f['max_price']] = [$f['max_price'], $f['min_price']];
        }
        if (isset($f['min_price']) || isset($f['max_price'])) {
            $understood[] = match (true) {
                isset($f['min_price'], $f['max_price']) => self::money((int) $f['min_price']).' to '.self::money((int) $f['max_price']),
                isset($f['max_price']) => 'under '.self::money((int) $f['max_price']),
                default => 'over '.self::money((int) $f['min_price']),
            };
        }

        if (preg_match('/(\d{1,2})\s*\+?\s*(?:or more\s+)?(?:'.self::BEDS.')\b/u', $t, $m) === 1 && (int) $m[1] > 0 && (int) $m[1] <= 10) {
            $f['beds'] = $m[1];
            $understood[] = $m[1].'+ bedrooms';
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d{1,2})(?:\.5)?\s*\+?\s*(?:'.self::BATHS.')\b/u', $t, $m) === 1 && (int) $m[1] > 0 && (int) $m[1] <= 10) {
            $f['baths'] = $m[1];
            $understood[] = $m[1].'+ bathrooms';
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d[\d,]{2,6})\s*\+?\s*(?:sq\.?\s*ft|sqft|square feet|sf|pies cuadrados|pies2)\b/u', $t, $m) === 1) {
            $sqft = (int) str_replace(',', '', $m[1]);
            if ($sqft >= 200 && $sqft <= 50000) {
                $f['min_sqft'] = (string) $sqft;
                $understood[] = number_format($sqft).'+ sq ft';
            }
            $t = str_replace($m[0], ' ', $t);
        }
        $types = [];
        foreach (self::TYPES as $words => $type) {
            if (preg_match('/\b('.$words.')\b/u', $t, $m) === 1) {
                $types[] = $type;
                $t = str_replace($m[0], ' ', $t);
            }
        }
        if ($types !== []) {
            $f['types'] = implode(',', array_unique($types));
            $understood[] = implode(', ', array_map(fn (string $x): string => match ($x) {
                'Residential Lease' => 'for rent', 'Residential Income' => 'multi-family', 'Commercial Sale' => 'commercial', default => strtolower($x),
            }, array_unique($types)));
        }
        $notFiltered = [];
        foreach (self::FEATURES as $words => $label) {
            if (preg_match('/\b('.$words.')\b/u', $t, $m) === 1) {
                $notFiltered[] = $label;
                $t = str_replace($m[0], ' ', $t);
            }
        }

        // The place: a ZIP, or the words after "in"/"en"/"near"/"cerca de".
        if (preg_match('/\b(\d{5})\b/', $t, $m) === 1) {
            $f['q'] = $m[1];
        } elseif (preg_match('/\b(?:in|en|near|around|cerca de|por)\s+([a-zà-ÿ][a-zà-ÿ\.\'\- ]{1,40}?)(?=\s*(?:,|\btx\b|\bwith\b|\bcon\b|\bthat\b|\bque\b|$))/u', rtrim($t), $m) === 1) {
            $place = trim(preg_replace('/\b(the|el|la|los|las|area|zona)\b/u', ' ', $m[1]) ?? $m[1]);
            $place = trim(preg_replace('/\s+/', ' ', $place) ?? $place);
            if ($place !== '') {
                $f['q'] = mb_convert_case($place, MB_CASE_TITLE);
            }
        }
        if (isset($f['q'])) {
            $understood[] = 'in '.$f['q'];
        }

        return ['filters' => $f, 'understood' => $understood, 'not_filtered' => array_values(array_unique($notFiltered)), 'language' => $spanish ? 'es' : 'en'];
    }

    /**
     * The "we searched for" words in the visitor's language.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    public static function say(array $phrases, string $language): array
    {
        if ($language !== 'es') {
            return $phrases;
        }
        $words = ['under ' => 'hasta ', 'over ' => 'más de ', ' to ' => ' a ', '+ bedrooms' => '+ recámaras', '+ bathrooms' => '+ baños', '+ sq ft' => '+ pies cuadrados',
            'for rent' => 'en renta', 'multi-family' => 'multifamiliar', 'commercial' => 'comercial', 'land' => 'terreno', 'farm' => 'rancho', 'pool' => 'piscina',
            'garage' => 'garaje', 'waterfront' => 'frente al agua', 'view' => 'vista', 'yard' => 'jardín', 'basement' => 'sótano', 'acreage' => 'acres',
            'new construction' => 'nueva construcción', 'condo or townhome' => 'condominio o casa adosada'];

        return array_map(fn (string $p): string => str_starts_with($p, 'in ') ? 'en '.substr($p, 3) : strtr($p, $words), $phrases);
    }

    private static function amount(string $number, string $unit, bool $rent = false): int
    {
        $n = (float) str_replace(',', '', $number);

        return (int) round(match ($unit) {
            'k', 'mil', 'thousand' => $n * 1000,
            'm', 'million', 'millones' => $n * 1_000_000,
            default => ! $rent && $n < 10000 ? $n * 1000 : $n, // "under 500" means $500k on a home for sale; rent is monthly
        });
    }

    private static function money(int $dollars): string
    {
        if ($dollars < 10000) {
            return '$'.number_format($dollars);
        }

        return $dollars >= 1_000_000 ? '$'.rtrim(rtrim(number_format($dollars / 1_000_000, 2), '0'), '.').'M' : '$'.number_format(intdiv($dollars, 1000)).'k';
    }
}
