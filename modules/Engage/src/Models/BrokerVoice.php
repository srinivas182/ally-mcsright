<?php

declare(strict_types=1);

namespace Modules\Engage\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A broker's AI voice for recognition messages only, made from their own signed and recorded consent (EN1, E21).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $voice_ref
 * @property string $status
 * @property string $consent_name
 * @property string $consent_text
 * @property CarbonImmutable $consent_at
 */
final class BrokerVoice extends Model
{
    use BelongsToTenant;

    protected $table = 'engage_voices';

    protected $fillable = ['user_id', 'voice_ref', 'status', 'consent_name', 'consent_text', 'consent_ip', 'recording_sha256', 'consent_at', 'deleted_at'];

    protected $hidden = ['voice_ref'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['voice_ref' => 'encrypted', 'consent_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }
}
