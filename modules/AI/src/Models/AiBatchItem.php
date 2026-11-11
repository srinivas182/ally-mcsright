<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One task in a batch. The permitted text (for the output policy) and the answer are encrypted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $batch_id
 * @property string $idempotency_key
 * @property string $prompt_key
 * @property int|null $user_id
 * @property string $status
 * @property string|null $permitted
 * @property string|null $result
 * @property CarbonImmutable $purge_after
 */
final class AiBatchItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['batch_id', 'idempotency_key', 'prompt_key', 'user_id', 'status', 'permitted', 'result', 'purge_after'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['permitted' => 'encrypted', 'result' => 'encrypted', 'purge_after' => 'immutable_datetime'];
    }
}
