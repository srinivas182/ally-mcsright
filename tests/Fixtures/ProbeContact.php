<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * Tenant-owned record used by AI data-protection tests.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string $notes
 */
final class ProbeContact extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'test_probe_contacts';

    protected $fillable = ['name', 'email', 'notes'];
}
