<?php

declare(strict_types=1);

namespace Modules\Sites\Reviews;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One review of an agent or the brokerage, from another site (specs/connect/20).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $user_id
 * @property int|null $connection_id
 * @property string $source
 * @property string $external_id
 * @property string|null $author
 * @property int|null $rating
 * @property string|null $text
 * @property string|null $url
 * @property CarbonImmutable $reviewed_at
 * @property string|null $reply
 * @property CarbonImmutable|null $replied_at
 * @property bool $hidden
 * @property string|null $hidden_reason
 * @property int|null $hidden_by
 */
final class Review extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_reviews';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'replied_at' => 'immutable_datetime', 'hidden' => 'boolean', 'rating' => 'integer', 'user_id' => 'integer'];
    }
}
