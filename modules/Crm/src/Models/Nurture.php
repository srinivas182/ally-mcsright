<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A contact's 12-month AI follow-up (specs/grow/09).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $contact_id
 * @property int|null $lead_id
 * @property string $status
 * @property string|null $stopped_reason
 * @property int $touches
 * @property int $unanswered
 * @property CarbonImmutable $next_at
 * @property CarbonImmutable|null $last_touch_at
 * @property string|null $last_reason
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable $created_at
 */
final class Nurture extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_nurtures';

    protected $fillable = ['contact_id', 'lead_id', 'status', 'next_at', 'ends_on'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['next_at' => 'immutable_datetime', 'last_touch_at' => 'immutable_datetime', 'ends_on' => 'immutable_date', 'touches' => 'integer', 'unanswered' => 'integer'];
    }
}
