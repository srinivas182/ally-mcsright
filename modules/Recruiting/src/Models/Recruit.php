<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An agent the brokerage is recruiting (RC1, specs/grow/17).
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $mls_agent_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $brokerage
 * @property string $stage
 * @property string $source
 * @property int|null $owner_user_id
 * @property string|null $next_step
 * @property CarbonImmutable|null $next_step_on
 * @property string|null $not_now_reason
 * @property CarbonImmutable|null $stage_changed_on
 * @property string|null $current_split
 * @property int|null $current_cap_cents
 * @property int|null $current_fee_cents
 * @property int|null $compare_plan_id
 * @property CarbonImmutable $created_at
 * @property-read MlsAgent|null $mlsAgent
 */
final class Recruit extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    /** Pipeline stages, in order, with the words agents and brokers see. */
    public const STAGES = ['prospect' => 'To reach', 'talking' => 'Talking', 'meeting' => 'Meeting set', 'offer' => 'Offer made', 'joined' => 'Joined', 'not_now' => 'Not now'];

    public const OPEN = ['prospect', 'talking', 'meeting', 'offer'];

    protected $table = 'rec_recruits';

    protected $fillable = ['mls_agent_id', 'name', 'email', 'phone', 'brokerage', 'stage', 'source', 'owner_user_id', 'next_step', 'next_step_on', 'not_now_reason',
        'stage_changed_on', 'current_split', 'current_cap_cents', 'current_fee_cents', 'compare_plan_id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return BelongsTo<MlsAgent, $this> */
    public function mlsAgent(): BelongsTo
    {
        return $this->belongsTo(MlsAgent::class);
    }

    /** @return HasMany<RecruitNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(RecruitNote::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['next_step_on' => 'immutable_date', 'stage_changed_on' => 'immutable_date', 'created_at' => 'immutable_datetime',
            'current_cap_cents' => 'integer', 'current_fee_cents' => 'integer'];
    }
}
