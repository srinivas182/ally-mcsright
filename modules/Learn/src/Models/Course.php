<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A training course (LN1): lessons of text, video or quiz.
 *
 * @property int $id
 * @property string $ulid
 * @property string $title
 * @property string|null $summary
 * @property string $source
 * @property string|null $starter_key
 * @property string $status
 * @property int $pass_mark
 * @property int $minutes
 * @property int|null $created_by
 */
final class Course extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'learn_courses';

    protected $fillable = ['title', 'summary', 'source', 'starter_key', 'status', 'pass_mark', 'minutes', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pass_mark' => 'integer', 'minutes' => 'integer'];
    }
}
