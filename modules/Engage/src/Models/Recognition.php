<?php

declare(strict_types=1);

namespace Modules\Engage\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A recognition message from the broker to an agent (EN1, E21): words, and a recording or the broker's consented AI voice.
 *
 * @property int $id
 * @property string $ulid
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string $occasion
 * @property string|null $text
 * @property string|null $audio_path
 * @property string $audio_kind
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $played_at
 * @property CarbonImmutable $created_at
 */
final class Recognition extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'engage_recognitions';

    protected $fillable = ['from_user_id', 'to_user_id', 'occasion', 'text', 'audio_path', 'audio_kind', 'sent_at', 'played_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'played_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
