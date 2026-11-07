<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Minimal tenant-owned model used to test tenant isolation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $body
 */
final class TenantNote extends Model
{
    use BelongsToTenant;

    protected $table = 'test_tenant_notes';

    protected $fillable = ['body', 'tenant_id'];
}
