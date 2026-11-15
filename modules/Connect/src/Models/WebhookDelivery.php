<?php

declare(strict_types=1);

namespace Modules\Connect\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $connection_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable $created_at
 */
final class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'connect_webhook_deliveries';

    protected $fillable = ['connection_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'last_error', 'delivered_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'delivered_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
