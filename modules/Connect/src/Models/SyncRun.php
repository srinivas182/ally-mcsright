<?php

declare(strict_types=1);

namespace Modules\Connect\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $connection_id
 * @property string $status
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property string|null $note
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class SyncRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'connect_sync_runs';

    protected $fillable = ['connection_id', 'status', 'created', 'updated', 'skipped', 'note', 'started_at', 'finished_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
