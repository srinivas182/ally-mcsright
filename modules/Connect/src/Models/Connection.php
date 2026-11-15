<?php

declare(strict_types=1);

namespace Modules\Connect\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One connected app for the brokerage (owner_user_id null) or one person (specs/connect/01).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $app_key
 * @property int|null $owner_user_id
 * @property string|null $label
 * @property string $status
 * @property array<string, string>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property string|null $cursor
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property int $failures
 * @property CarbonImmutable|null $alerted_at
 * @property int|null $connected_by
 * @property CarbonImmutable $created_at
 */
final class Connection extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'connect_connections';

    protected $fillable = ['app_key', 'owner_user_id', 'label', 'status', 'credentials', 'settings', 'cursor', 'last_synced_at', 'last_error', 'failures', 'alerted_at', 'connected_by'];

    protected $hidden = ['credentials'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    public function data(): ConnectionData
    {
        return new ConnectionData($this->id, $this->ulid, $this->app_key, $this->owner_user_id, $this->credentials ?? [], $this->settings ?? [], $this->cursor);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'settings' => 'array', 'last_synced_at' => 'immutable_datetime', 'alerted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime'];
    }
}
