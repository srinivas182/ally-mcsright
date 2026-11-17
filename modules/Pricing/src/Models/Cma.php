<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A comparative market analysis (CM1).
 *
 * @property int $id
 * @property string $ulid
 * @property int $owner_user_id
 * @property int|null $contact_id
 * @property int|null $deal_id
 * @property string $title
 * @property array<string, mixed> $subject
 * @property list<array{listing_id: int, on: bool, overrides?: array<string, int>}> $picks
 * @property array<string, mixed>|null $result
 * @property string|null $summary
 * @property string $lang
 * @property array<string, mixed>|null $net
 * @property string|null $share_hash
 * @property CarbonImmutable|null $shared_at
 * @property CarbonImmutable|null $share_expires_at
 * @property int $views
 * @property CarbonImmutable|null $last_viewed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Cma extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'pricing_cmas';

    protected $fillable = ['owner_user_id', 'contact_id', 'deal_id', 'title', 'subject', 'picks', 'result', 'summary', 'lang', 'net', 'share_hash', 'shared_at',
        'share_expires_at', 'views', 'last_viewed_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subject' => 'array', 'picks' => 'array', 'result' => 'array', 'net' => 'array', 'shared_at' => 'immutable_datetime', 'share_expires_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'views' => 'integer'];
    }
}
