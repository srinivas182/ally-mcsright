<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Crm\Enums\Relation;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $related_contact_id
 * @property Relation $relation
 * @property Contact $related
 */
final class Relationship extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'crm_relationships';

    protected $fillable = ['contact_id', 'related_contact_id', 'relation'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['relation' => Relation::class];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'related_contact_id');
    }
}
