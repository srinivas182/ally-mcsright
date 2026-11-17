<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A recruiting campaign (RC2): emails and texts on set days after a recruit starts it.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property list<array{day: int, channel: string, subject: string|null, body: string}> $steps
 * @property string $status
 * @property int|null $created_by
 */
final class Campaign extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'rec_campaigns';

    protected $fillable = ['name', 'steps', 'status', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['steps' => 'array'];
    }
}
