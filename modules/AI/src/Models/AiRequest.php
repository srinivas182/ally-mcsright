<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * Log of one AI attempt: who, which feature and model, tokens, cost and the source records
 * used. Never stores the prompt or the output.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $feature_key
 * @property string $prompt_key
 * @property string $prompt_version
 * @property string|null $provider_key
 * @property string|null $model_id
 * @property int $attempt
 * @property string $status
 * @property string|null $failure_reason
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_microcents
 * @property list<string> $source_record_ids
 * @property string|null $output_policy_result
 * @property string $idempotency_key
 */
final class AiRequest extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'feature_key', 'prompt_key', 'prompt_version', 'provider_key', 'model_id', 'attempt',
        'status', 'failure_reason', 'tokens_in', 'tokens_out', 'cache_read_tokens', 'cost_microcents',
        'latency_ms', 'source_record_ids', 'output_policy_result', 'idempotency_key',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source_record_ids' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
