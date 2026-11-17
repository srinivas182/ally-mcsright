<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A saved buyer cost estimate (CM1).
 *
 * @property int $id
 * @property string $ulid
 * @property int $owner_user_id
 * @property int|null $contact_id
 * @property int|null $deal_id
 * @property string $kind
 * @property string $title
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $result
 * @property string $lang
 * @property string|null $share_hash
 * @property CarbonImmutable|null $share_expires_at
 * @property int $views
 * @property CarbonImmutable|null $last_viewed_at
 * @property CarbonImmutable $updated_at
 */
final class Sheet extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'pricing_sheets';

    protected $fillable = ['owner_user_id', 'contact_id', 'deal_id', 'kind', 'title', 'inputs', 'result', 'lang', 'share_hash', 'share_expires_at', 'views', 'last_viewed_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['inputs' => 'array', 'result' => 'array', 'share_expires_at' => 'immutable_datetime', 'last_viewed_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime', 'views' => 'integer'];
    }
}
