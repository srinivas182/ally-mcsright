<?php

declare(strict_types=1);

namespace Modules\Setup\Imports;

/**
 * What a file from another system usually looks like (SU1, specs/setup/01): for each kind of file (people, agents,
 * deals), the columns we fill, the words other systems use for them, and the column sets of common exports so a file
 * can be recognised ("This looks like a SkySlope transaction export"). Recognition is by column names only; nothing
 * in the rows is sent anywhere to work it out.
 */
final class Formats
{
    public const KINDS = ['people' => 'People (contacts and leads)', 'agents' => 'Agents (your roster)', 'deals' => 'Deals (transactions)'];

    /** Our fields per kind, with the words other systems use for each (lower case, spaces for _ and -). */
    public const FIELDS = [
        'people' => [
            'first_name' => ['first name', 'firstname', 'first', 'given name'],
            'last_name' => ['last name', 'lastname', 'last', 'surname'],
            'full_name' => ['name', 'full name', 'contact name', 'lead name'],
            'email' => ['email', 'email address', 'email 1', 'primary email', 'e mail'],
            'phone' => ['phone', 'phone 1', 'mobile', 'cell', 'cell phone', 'mobile phone', 'phone number', 'primary phone'],
            'address_line1' => ['address', 'street', 'street address', 'mailing address', 'property address'],
            'city' => ['city', 'town'],
            'state' => ['state', 'st', 'province'],
            'zip' => ['zip', 'zip code', 'zipcode', 'postal code'],
            'tags' => ['tags', 'tag', 'hashtags', 'groups', 'labels'],
            'notes' => ['notes', 'note', 'comments', 'background'],
            'last_contact' => ['last contact', 'last contacted', 'last activity', 'last activity date', 'last touch'],
        ],
        'agents' => [
            'first_name' => ['first name', 'firstname', 'first'],
            'last_name' => ['last name', 'lastname', 'last', 'surname'],
            'full_name' => ['name', 'agent', 'agent name', 'full name', 'member name'],
            'email' => ['email', 'email address', 'agent email', 'work email'],
            'phone' => ['phone', 'mobile', 'cell', 'cell phone', 'agent phone', 'phone number'],
            'licence_number' => ['license', 'license number', 'license #', 'license no', 'licence number', 'license id', 'real estate license'],
            'licence_state' => ['license state', 'licence state', 'state licensed'],
            'start_date' => ['start date', 'join date', 'joined', 'hire date', 'date joined'],
        ],
        'deals' => [
            'id' => ['id', 'transaction id', 'loop id', 'file id', 'deal id', 'transaction number'],
            'street' => ['address', 'street', 'property address', 'street address', 'property'],
            'city' => ['city'],
            'state_code' => ['state', 'st'],
            'postal_code' => ['zip', 'zip code', 'postal code', 'zipcode'],
            'mls_number' => ['mls', 'mls number', 'mls #', 'mls id', 'listing id'],
            'price' => ['price', 'sale price', 'sales price', 'purchase price', 'purchase/sale price', 'contract price', 'list price', 'listing price'],
            'gci' => ['gci', 'total commission', 'gross commission', 'commission', 'gross commission income'],
            'type' => ['type', 'side', 'representing', 'transaction type', 'deal type', 'representation'],
            'status' => ['status', 'stage', 'transaction status', 'loop status'],
            'contract_date' => ['contract date', 'acceptance date', 'under contract date', 'effective date', 'acceptance'],
            'closing_date' => ['closing date', 'close date', 'escrow closing date', 'closed date', 'settlement date', 'expected closing'],
            'agent_email' => ['agent email', 'listing agent email', 'buyer agent email'],
            'agent_name' => ['agent', 'agent name', 'listing agent', 'primary agent', 'created by', 'owner'],
        ],
    ];

    /** The fields a file needs before it can be brought in. */
    public const REQUIRED = [
        'people' => [['email', 'phone'], ['first_name', 'last_name', 'full_name']],
        'agents' => [['email'], ['first_name', 'last_name', 'full_name']],
        'deals' => [['street'], ['state_code'], ['status']],
    ];

    /**
     * Common exports, recognised by the columns they always have. Column names come from each system's standard
     * export and can change; an unrecognised file still works through the field words above.
     *
     * @var array<string, array{kind: string, label: string, columns: list<string>}>
     */
    public const SOURCES = [
        'followupboss' => ['kind' => 'people', 'label' => 'Follow Up Boss people', 'columns' => ['first name', 'last name', 'email 1', 'phone 1', 'stage', 'assigned to']],
        'kvcore' => ['kind' => 'people', 'label' => 'kvCORE / BoldTrail contacts', 'columns' => ['first name', 'last name', 'email', 'phone', 'status', 'hashtags']],
        'lofty' => ['kind' => 'people', 'label' => 'Lofty leads', 'columns' => ['first name', 'last name', 'email', 'phone', 'lead type', 'assigned agent']],
        'brokermint_agents' => ['kind' => 'agents', 'label' => 'Brokermint agents', 'columns' => ['first name', 'last name', 'email', 'license number', 'commission plan']],
        'lonewolf_agents' => ['kind' => 'agents', 'label' => 'Lone Wolf agents', 'columns' => ['agent name', 'email', 'license #', 'start date']],
        'brokermint_deals' => ['kind' => 'deals', 'label' => 'Brokermint transactions', 'columns' => ['address', 'city', 'state', 'status', 'closing date', 'representing', 'total commission']],
        'skyslope' => ['kind' => 'deals', 'label' => 'SkySlope transactions', 'columns' => ['property address', 'city', 'state', 'zip code', 'status', 'escrow closing date', 'agent name']],
        'dotloop' => ['kind' => 'deals', 'label' => 'dotloop loops', 'columns' => ['loop name', 'property address', 'loop status', 'purchase/sale price', 'closing date']],
    ];

    /** Deal statuses in other systems, mapped to ours. */
    public const STATUS_WORDS = [
        'closed' => ['closed', 'sold', 'settled', 'complete', 'completed', 'funded'],
        'under_contract' => ['pending', 'under contract', 'in escrow', 'contingent', 'active under contract', 'escrow', 'option pending'],
        'pre_contract' => ['active', 'coming soon', 'pre contract', 'pre listing', 'listing', 'new', 'private listing', 'active listing'],
        'fell_through' => ['cancelled', 'canceled', 'withdrawn', 'expired', 'terminated', 'fell through', 'archived'],
    ];

    /** Deal sides in other systems, mapped to ours. */
    public const TYPE_WORDS = [
        'listing' => ['listing', 'seller', 'sell', 'sale', 'list side', 'seller side'],
        'buyer' => ['buyer', 'buy', 'purchase', 'buyer side', 'selling'],
        'dual' => ['dual', 'both', 'dual agency', 'double'],
        'lease' => ['lease', 'rental', 'tenant', 'landlord', 'rent'],
    ];

    public static function normal(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(str_replace(['_', '-'], ' ', $header))) ?? '');
    }

    /** @param  array<string, list<string>>  $words */
    public static function word(string $value, array $words): ?string
    {
        $v = self::normal($value);
        foreach ($words as $ours => $theirs) {
            if ($v === $ours || in_array($v, $theirs, true)) {
                return $ours;
            }
        }
        foreach ($words as $ours => $theirs) {
            foreach ($theirs as $t) {
                if ($t !== '' && str_contains($v, $t)) {
                    return $ours;
                }
            }
        }

        return null;
    }
}
