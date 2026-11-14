<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

/**
 * Turns fillable PDF fields into RightAlly form fields: type (text, date, checkbox, signature, initials, date
 * signed), who signs (seller, buyer, either agent), and what auto-fills it (address, price, names, dates…), from
 * the field's name ("Seller1Signature", "property_address", "List Price"). Anything it cannot place with
 * confidence is left for review: signature fields with no recognisable signer get the role "unassigned", which
 * stops the packet being sent until someone assigns it in the visual editor.
 */
final class FieldMapper
{
    public const UNASSIGNED = 'unassigned';

    /** Phrase in the field name => binding. First match wins, so more specific phrases come first. */
    private const BINDINGS = [
        'seller name' => 'seller.names', 'sellers name' => 'seller.names', 'owner name' => 'seller.names', 'seller 1 name' => 'seller.1.name', 'seller 2 name' => 'seller.2.name',
        'buyer name' => 'buyer.names', 'buyers name' => 'buyer.names', 'purchaser name' => 'buyer.names', 'buyer 1 name' => 'buyer.1.name', 'buyer 2 name' => 'buyer.2.name',
        'agent email' => 'agent.email', 'licensee email' => 'agent.email', 'agent phone' => 'agent.phone', 'licensee phone' => 'agent.phone',
        'agent name' => 'agent.name', 'licensee name' => 'agent.name', 'listing agent' => 'agent.name',
        'brokerage' => 'brokerage.name', 'broker firm' => 'brokerage.name', 'firm name' => 'brokerage.name', 'broker name' => 'brokerage.name',
        'closing date' => 'deal.closing_date', 'close of escrow' => 'deal.closing_date', 'effective date' => 'deal.contract_date', 'contract date' => 'deal.contract_date',
        'mls' => 'deal.mls', 'zip' => 'deal.postal_code', 'postal' => 'deal.postal_code', 'city' => 'deal.city',
        'property address' => 'deal.address', 'street address' => 'deal.address', 'address' => 'deal.address',
        'list price' => 'deal.price', 'sales price' => 'deal.price', 'purchase price' => 'deal.price', 'price' => 'deal.price',
    ];

    /**
     * @param  list<array{name: string, type: string, page: int, rect: array{0: float, 1: float, 2: float, 3: float}}>  $raw
     * @param  array<int|string, mixed>  $sizes  [[width, height], ...] in points
     * @return list<array<string, mixed>>
     */
    public static function map(array $raw, array $sizes): array
    {
        $fields = [];
        $keys = [];
        foreach ($raw as $f) {
            $size = $sizes[$f['page'] - 1] ?? null;
            if (! is_array($size)) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = $f['rect'];
            $w = $x2 - $x1;
            $h = $y2 - $y1;
            $y = (float) $size[1] - $y2; // PDF origin is bottom-left; ours is top-left
            if ($w < 2 || $h < 2 || $x1 < 0 || $y < 0 || $x2 > (float) $size[0] + 0.5 || $y2 > (float) $size[1] + 0.5) {
                continue;
            }
            $words = self::words($f['name']);
            $role = self::role($words);
            $type = match (true) {
                $f['type'] === 'signature' || preg_match('/\b(signature|sign here|signed by)\b/', $words) === 1 && ! str_contains($words, 'date') => 'signature',
                preg_match('/\binitials?\b/', $words) === 1 => 'initials',
                preg_match('/\bdate\b/', $words) === 1 && preg_match('/\b(sign|signed|signature)\b/', $words) === 1 => 'date_signed',
                $f['type'] === 'checkbox' => 'checkbox',
                preg_match('/\bdate\b/', $words) === 1 => 'date',
                default => 'text',
            };
            $signer = in_array($type, ['signature', 'initials', 'date_signed'], true);
            $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', $words), '_'), 0, 34);
            $base = preg_match('/^[a-z]/', $base) === 1 ? $base : 'f_'.$base;
            $key = $base;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = $base.'_'.$n;
            }
            $keys[$key] = true;
            $fields[] = ['key' => $key, 'type' => $type, 'page' => $f['page'], 'x' => round($x1, 1), 'y' => round($y, 1), 'w' => round($w, 1), 'h' => round($h, 1),
                'role' => $signer ? ($role ?? self::UNASSIGNED) : null, 'bind' => $signer || $type === 'checkbox' ? null : self::binding($words),
                'required' => false, 'label' => mb_substr(ucfirst($words), 0, 80)];
        }

        return $fields;
    }

    /** "Seller1Signature" / "seller_1-signature" → "seller 1 signature". */
    public static function words(string $name): string
    {
        $name = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        $name = (string) preg_replace('/([A-Za-z])(\d)/', '$1 $2', $name);
        $name = (string) preg_replace('/(\d)([A-Za-z])/', '$1 $2', $name);

        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name))));
    }

    private static function role(string $words): ?string
    {
        $n = preg_match('/\b(?:seller|owner|buyer|purchaser) ?(\d)\b/', $words, $m) === 1 ? (int) $m[1] : 1;

        return match (true) {
            preg_match('/\b(listing (agent|broker|licensee)|seller s? (agent|broker))\b/', $words) === 1 => 'listing_agent',
            preg_match('/\b((buyer|selling|cooperating) s? (agent|broker|licensee))\b/', $words) === 1 => 'buyer_agent',
            preg_match('/\b(seller|owner)\b/', $words) === 1 => 'seller'.$n,
            preg_match('/\b(buyer|purchaser)\b/', $words) === 1 => 'buyer'.$n,
            preg_match('/\b(agent|broker|licensee)\b/', $words) === 1 => 'listing_agent',
            default => null,
        };
    }

    private static function binding(string $words): ?string
    {
        foreach (self::BINDINGS as $phrase => $bind) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/', $words) === 1) {
                return $bind;
            }
        }

        return null;
    }
}
