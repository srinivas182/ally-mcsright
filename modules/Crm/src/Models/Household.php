<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * People who buy or sell together (e.g. a couple), so their contacts stay linked.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 */
final class Household extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_households';

    protected $fillable = ['name'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'crm_household_members', 'household_id', 'contact_id')->withPivot('role');
    }
}
