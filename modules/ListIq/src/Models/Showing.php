<?php

declare(strict_types=1);

namespace Modules\ListIq\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A showing of a listing (SH1).
 *
 * @property int $id
 * @property string $ulid
 * @property int $listing_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $brokerage
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $status
 * @property string|null $decline_reason
 * @property int|null $decided_by
 * @property string|null $feedback_hash
 * @property int $feedback_asks
 * @property CarbonImmutable|null $feedback_asked_at
 * @property array{interest: int, price: string, comments: string|null}|null $feedback
 * @property CarbonImmutable|null $feedback_at
 */
final class Showing extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'listiq_showings';

    protected $fillable = ['listing_id', 'name', 'email', 'phone', 'brokerage', 'starts_at', 'ends_at', 'status', 'decline_reason', 'decided_by', 'feedback_hash', 'feedback_asks',
        'feedback_asked_at', 'feedback', 'feedback_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'feedback_asked_at' => 'immutable_datetime', 'feedback' => 'array',
            'feedback_at' => 'immutable_datetime', 'feedback_asks' => 'integer'];
    }
}
