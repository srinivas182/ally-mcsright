<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A recruit in a campaign (RC2).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $recruit_id
 * @property int $next_step
 * @property CarbonImmutable|null $next_at
 * @property string $status
 * @property string|null $stopped_reason
 * @property int|null $started_by
 * @property CarbonImmutable $created_at
 * @property-read Campaign $campaign
 * @property-read Recruit $recruit
 */
final class Enrollment extends Model
{
    use BelongsToTenant;

    protected $table = 'rec_enrollments';

    protected $fillable = ['campaign_id', 'recruit_id', 'next_step', 'next_at', 'status', 'stopped_reason', 'started_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Recruit, $this> */
    public function recruit(): BelongsTo
    {
        return $this->belongsTo(Recruit::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['next_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'next_step' => 'integer'];
    }
}
