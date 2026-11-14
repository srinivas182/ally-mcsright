<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A contact's saved home search with email alerts (specs/grow/08).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $contact_id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property string $frequency
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_sent_at
 * @property int $sent_count
 * @property CarbonImmutable|null $unsubscribed_at
 * @property CarbonImmutable $created_at
 * @property Contact $contact
 */
final class SavedSearch extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const FREQUENCIES = ['instant', 'daily', 'weekly', 'off'];

    protected $table = 'crm_saved_searches';

    protected $fillable = ['contact_id', 'user_id', 'name', 'filters', 'frequency', 'last_checked_at', 'last_sent_at', 'sent_count', 'unsubscribed_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'last_checked_at' => 'immutable_datetime', 'last_sent_at' => 'immutable_datetime', 'unsubscribed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
