<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A version of a plan. Drafts can change; published versions never do (their rules and
 * effective date are frozen). Changes always create a new version.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $plan_id
 * @property int $version
 * @property VersionStatus $status
 * @property CarbonImmutable|null $effective_from
 * @property array<string, mixed> $rules
 * @property int|null $published_by
 * @property CarbonImmutable|null $published_at
 * @property string|null $change_note
 * @property Plan $plan
 */
final class PlanVersion extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'comp_plan_versions';

    protected $fillable = ['plan_id', 'version', 'status', 'effective_from', 'rules', 'published_by', 'published_at', 'change_note'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            if ($version->getOriginal('status') === VersionStatus::Draft->value || $version->getOriginal('status') === VersionStatus::Draft) {
                return;
            }

            // Published versions: only the status may move from published to superseded.
            $dirty = array_keys($version->getDirty());
            if ($dirty !== ['status'] && array_diff($dirty, ['status', 'updated_at']) !== []) {
                throw new LogicException('Published plan versions are immutable; create a new version instead.');
            }
        });

        self::deleting(function (self $version): void {
            if ($version->status !== VersionStatus::Draft) {
                throw new LogicException('Published plan versions cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'effective_from' => 'immutable_date',
            'rules' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function commissionRules(): CommissionRules
    {
        return CommissionRules::fromArray($this->rules);
    }
}
