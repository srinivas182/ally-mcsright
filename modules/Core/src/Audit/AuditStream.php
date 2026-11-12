<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A brokerage's audit streaming destination. Credentials are encrypted at rest and never shown again.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $type
 * @property string $name
 * @property array<string, string> $config
 * @property string $status
 * @property int $cursor_seq
 * @property CarbonImmutable|null $last_success_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $failing_since
 * @property int $created_by
 */
final class AuditStream extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const TYPES = ['webhook', 's3', 'datadog', 'splunk'];

    protected $table = 'audit_streams';

    protected $fillable = ['type', 'name', 'config', 'status', 'cursor_seq', 'last_success_at', 'last_error', 'failing_since', 'created_by'];

    protected $hidden = ['config'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'cursor_seq' => 'integer', 'last_success_at' => 'immutable_datetime', 'failing_since' => 'immutable_datetime'];
    }
}
