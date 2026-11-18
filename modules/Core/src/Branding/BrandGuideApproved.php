<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The owner approved what the AI read from the brand guide and asked to use its colours and fonts too (BV1). Modules
 * with their own brand kit (Ally Studio) update it.
 */
final class BrandGuideApproved
{
    use Dispatchable;

    /**
     * @param  list<string>  $colors
     * @param  list<string>  $fonts
     */
    public function __construct(public readonly int $tenantId, public readonly array $colors, public readonly array $fonts, public readonly ?string $disclaimer, public readonly int $byUserId) {}
}
