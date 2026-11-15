<?php

declare(strict_types=1);

namespace Modules\Crm\Postcards;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A postcard mailing (specs/connect/18).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $kind
 * @property string $size
 * @property string $smart_list
 * @property string|null $listing_ulid
 * @property string $headline
 * @property string $message
 * @property string $link
 * @property array{name: string, address_line1: string, city: string, state: string, zip: string} $from
 * @property string $status
 * @property int $cards
 * @property int $undeliverable
 * @property int $cents_per_card
 * @property int $credits
 * @property int $credits_refunded
 * @property CarbonImmutable|null $send_date
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
final class PostcardRun extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_postcard_runs';

    protected $fillable = ['user_id', 'kind', 'size', 'smart_list', 'listing_ulid', 'headline', 'message', 'link', 'from', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['from' => 'array', 'send_date' => 'immutable_date', 'cards' => 'integer', 'undeliverable' => 'integer', 'cents_per_card' => 'integer', 'credits' => 'integer',
            'credits_refunded' => 'integer'];
    }
}
