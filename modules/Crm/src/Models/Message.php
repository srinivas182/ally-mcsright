<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A text or email in a conversation. The body is encrypted at rest.
 *
 * @property int $id
 * @property string $ulid
 * @property int $conversation_id
 * @property int $contact_id
 * @property string $channel
 * @property string $direction
 * @property int|null $user_id
 * @property string|null $subject
 * @property string $body
 * @property string $status
 * @property string|null $provider_id
 * @property string|null $error
 * @property bool $ai_drafted
 * @property CarbonImmutable $created_at
 */
final class Message extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_messages';

    protected $fillable = ['conversation_id', 'contact_id', 'channel', 'direction', 'user_id', 'subject', 'body', 'status', 'provider_id', 'error', 'ai_drafted'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'ai_drafted' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
