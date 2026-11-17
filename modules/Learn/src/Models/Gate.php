<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A tool that needs a course first (LN2).
 *
 * @property int $id
 * @property string $tool
 * @property int $course_id
 * @property int|null $set_by
 */
final class Gate extends Model
{
    use BelongsToTenant;

    protected $table = 'learn_gates';

    protected $fillable = ['tool', 'course_id', 'set_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
