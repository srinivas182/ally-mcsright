<?php

declare(strict_types=1);

namespace Modules\Ads;

use App\Models\User;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Crm\FairHousing;

/**
 * The AI's first draft of a listing ad (specs/ads/01), checked against Fair Housing wording before anyone sees it.
 * The person can edit everything; whatever is published is checked again.
 */
final class AdCopy
{
    public const FEATURE = 'ads.listing_copy';

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $home  the listing, as shown on the website
     * @return array{headline: string, body: string, flags: list<string>}
     */
    public function draft(array $home, User $by): array
    {
        $facts = array_filter(['address' => $home['address'] ?? null, 'city' => $home['city'] ?? null, 'price' => isset($home['price']) ? '$'.number_format((int) $home['price']) : null,
            'beds' => $home['beds'] ?? null, 'baths' => $home['baths'] ?? null, 'sqft' => $home['sqft'] ?? null, 'type' => $home['property_sub_type'] ?? null,
            'year_built' => $home['year_built'] ?? null, 'remarks' => isset($home['remarks']) ? mb_substr(strip_tags((string) $home['remarks']), 0, 1500) : null]);
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'ads.listing_copy', 'ads-copy:'.($home['ulid'] ?? '').':'.$by->id.':'.now()->timestamp,
            untrustedContent: [(string) json_encode($facts)], outputSchema: ['type' => 'object', 'properties' => ['headline' => ['type' => 'string'], 'body' => ['type' => 'string']]],
            userId: $by->id));
        $headline = mb_substr(trim((string) ($result->json['headline'] ?? '')), 0, 40);
        $body = mb_substr(trim((string) ($result->json['body'] ?? '')), 0, 400);

        return ['headline' => $headline, 'body' => $body, 'flags' => FairHousing::flags($headline.' '.$body)];
    }
}
