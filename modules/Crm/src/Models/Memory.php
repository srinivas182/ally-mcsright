<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A small personal detail for a human touch ("dog Luna"). Encrypted; shown to the agent only;
 * never used for search, filters or marketing.
 *
 * @property int $id
 * @property int $contact_id
 * @property string $category
 * @property string $detail
 * @property int|null $source_activity_id
 * @property int|null $created_by
 */
final class Memory extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['family', 'pet', 'hobby', 'preference', 'milestone', 'other'];

    protected $table = 'crm_memories';

    protected $fillable = ['contact_id', 'category', 'detail', 'source_activity_id', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['detail' => 'encrypted'];
    }
}
