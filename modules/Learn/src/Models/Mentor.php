<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * An agent who offered to mentor new agents (LN2).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $strengths
 * @property int $max_mentees
 * @property bool $active
 */
final class Mentor extends Model
{
    use BelongsToTenant;

    protected $table = 'learn_mentors';

    protected $fillable = ['user_id', 'strengths', 'max_mentees', 'active'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'max_mentees' => 'integer'];
    }
}
