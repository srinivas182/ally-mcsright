<?php

declare(strict_types=1);

namespace Modules\AI\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One turn in a conversation with "Your AI Assistant" (specs/know/03).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $conversation
 * @property string $role
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property string|null $status
 * @property CarbonImmutable $created_at
 */
final class AssistantTurn extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected $table = 'ai_assistant_turns';

    protected $fillable = ['user_id', 'conversation', 'role', 'body', 'data', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'data' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }
}
