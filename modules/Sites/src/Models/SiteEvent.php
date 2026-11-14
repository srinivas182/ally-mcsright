<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One thing a visitor did: page, search, listing, favourite, unfavourite, compare, mortgage, valuation, signup, lead.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $visitor_id
 * @property string $type
 * @property string|null $listing_ulid
 * @property string|null $path
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $created_at
 */
final class SiteEvent extends Model
{
    use BelongsToTenant;

    public const TYPES = ['page', 'search', 'listing', 'favourite', 'unfavourite', 'compare', 'mortgage', 'valuation', 'signup', 'lead'];

    public const UPDATED_AT = null;

    protected $table = 'site_events';

    protected $fillable = ['visitor_id', 'type', 'listing_ulid', 'path', 'data', 'created_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
