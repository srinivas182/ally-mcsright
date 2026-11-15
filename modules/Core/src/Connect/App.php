<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * One app in the Connect catalog (specs/connect/00): what it is, who may connect it, how it signs in and what it
 * syncs. Modules declare apps in code; a "soon" app is listed so people can ask for it.
 *
 * - scope: brokerage (owners and staff connect it for everyone), agent (each agent connects their own), both.
 * - auth: oauth (sign in to the other app), api_key (paste a key), webhook (we send events to a URL),
 *   email (a forwarding address), link (a built-in RightAlly screen does the job).
 * - fields: what the connect form asks for (api_key and webhook apps); secret fields are write-only.
 */
final readonly class App
{
    public const CATEGORIES = [
        'import' => 'Import and automation',
        'calendar' => 'Calendar and email',
        'crm' => 'CRM',
        'leads' => 'Lead sources',
        'ads' => 'Ads',
        'transactions' => 'Transaction management',
        'accounting' => 'Accounting',
        'showings' => 'Showings and open houses',
        'marketing' => 'Marketing',
        'phones' => 'Phones',
        'data' => 'Property data',
        'partners' => 'Lenders, title and escrow',
        'reviews' => 'Reviews',
    ];

    /**
     * @param  list<string>  $syncs
     * @param  list<array{key: string, label: string, type: string, secret?: bool, help?: string, options?: array<string, string>}>  $fields
     * @param  class-string<Connector>|null  $driver
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $category,
        public string $summary,
        public string $scope = 'brokerage',
        public string $auth = 'link',
        public array $syncs = [],
        public string $cost = 'Free',
        public string $status = 'available',
        public ?string $link = null,
        public bool $multiple = false,
        public array $fields = [],
        public ?string $driver = null,
        public ?string $vendorCost = null,
    ) {}

    public function available(): bool
    {
        return $this->status === 'available';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'category' => $this->category, 'category_name' => self::CATEGORIES[$this->category] ?? $this->category,
            'summary' => $this->summary, 'scope' => $this->scope, 'auth' => $this->auth, 'syncs' => $this->syncs, 'cost' => $this->cost, 'vendor_cost' => $this->vendorCost,
            'status' => $this->status, 'link' => $this->link, 'multiple' => $this->multiple,
            'fields' => array_map(fn (array $f): array => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'secret' => $f['secret'] ?? false,
                'help' => $f['help'] ?? null, 'options' => $f['options'] ?? null], $this->fields)];
    }
}
