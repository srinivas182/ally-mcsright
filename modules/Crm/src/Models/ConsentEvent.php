<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Append-only consent history (TCPA / CAN-SPAM evidence). Never updated or deleted.
 *
 * @property int $id
 * @property int $contact_id
 * @property string $channel
 * @property string $action
 * @property string $method
 * @property string|null $consent_text
 * @property string|null $phone
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $user_id
 * @property CarbonImmutable $occurred_at
 */
final class ConsentEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'crm_consent_events';

    protected $fillable = ['contact_id', 'channel', 'action', 'method', 'consent_text', 'phone', 'ip', 'user_agent', 'user_id', 'occurred_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Consent history is append-only.'));
        self::deleting(fn () => throw new LogicException('Consent history is append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
