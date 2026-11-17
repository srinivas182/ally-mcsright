<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One lesson in a course (LN1).
 *
 * @property int $id
 * @property int $course_id
 * @property int $sort
 * @property string $title
 * @property string $kind
 * @property string|null $body
 * @property string|null $video_url
 * @property list<array{q: string, options: list<string>, answer: int, why?: string}>|null $questions
 */
final class Lesson extends Model
{
    use BelongsToTenant;

    public const KINDS = ['text', 'video', 'quiz'];

    protected $table = 'learn_lessons';

    protected $fillable = ['course_id', 'sort', 'title', 'kind', 'body', 'video_url', 'questions'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['questions' => 'array', 'sort' => 'integer'];
    }
}
