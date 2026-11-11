<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $referrer_contact_id
 * @property int $referred_contact_id
 * @property string $status
 * @property CarbonImmutable|null $closed_at
 * @property int|null $created_by
 * @property Contact $referrer
 * @property Contact $referred
 */
final class Referral extends Model
{
    use BelongsToTenant;

    protected $table = 'crm_referrals';

    protected $fillable = ['referrer_contact_id', 'referred_contact_id', 'status', 'closed_at', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referrer_contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referred_contact_id');
    }
}
