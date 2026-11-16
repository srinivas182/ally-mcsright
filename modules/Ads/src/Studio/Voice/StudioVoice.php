<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Voice;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * An agent's own AI voice and the consent behind it (specs/grow/13, SA5b). The vendor's id is encrypted; no
 * recording is kept.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $vendor
 * @property string|null $voice_ref
 * @property string $status
 * @property string $consent_name
 * @property string $consent_text
 * @property int $consent_version
 * @property string|null $consent_ip
 * @property string $recording_sha256
 * @property CarbonImmutable $consent_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $deleted_at
 * @property string|null $deleted_reason
 */
final class StudioVoice extends Model
{
    use BelongsToTenant;

    protected $table = 'studio_voices';

    protected $guarded = ['id'];

    protected $hidden = ['voice_ref'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['voice_ref' => 'encrypted', 'consent_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime',
            'user_id' => 'integer', 'consent_version' => 'integer'];
    }
}
