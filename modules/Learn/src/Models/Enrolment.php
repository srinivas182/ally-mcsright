<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Someone taking a course, assigned or self-chosen (LN1).
 *
 * @property int $id
 * @property int $course_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property CarbonImmutable|null $due_on
 * @property list<int>|null $done
 * @property array<string, int>|null $scores
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $reminded_at
 */
final class Enrolment extends Model
{
    use BelongsToTenant;

    protected $table = 'learn_enrolments';

    protected $fillable = ['course_id', 'user_id', 'assigned_by', 'due_on', 'done', 'scores', 'started_at', 'completed_at', 'reminded_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'done' => 'array', 'scores' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime'];
    }
}
