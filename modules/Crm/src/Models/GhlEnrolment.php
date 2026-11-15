<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * RightAlly contacts kept in a GoHighLevel workflow, campaign or tag (specs/connect/15, C7a-2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $connection_id
 * @property string $name
 * @property string $target_type
 * @property string $target_id
 * @property string $target_name
 * @property string $source_type
 * @property string $source_value
 * @property string $channel
 * @property bool $active
 * @property int $enrolled
 * @property int $left_out
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_error
 * @property int $created_by
 */
final class GhlEnrolment extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_ghl_enrolments';

    protected $fillable = ['connection_id', 'name', 'target_type', 'target_id', 'target_name', 'source_type', 'source_value', 'channel', 'active', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'enrolled' => 'integer', 'left_out' => 'integer', 'last_run_at' => 'immutable_datetime'];
    }
}
