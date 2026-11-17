<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;

/**
 * Which model serves an AI feature, its ordered fallbacks and limits.
 *
 * @property int $id
 * @property string $feature_key
 * @property int $primary_model_id
 * @property list<int> $fallback_model_ids
 * @property string $temperature
 * @property int $max_output_tokens
 * @property int $timeout_seconds
 * @property bool $locked
 * @property string $credit_action_key
 */
final class AiFeatureRoute extends Model
{
    protected $fillable = [
        'feature_key', 'primary_model_id', 'fallback_model_ids', 'temperature', 'max_output_tokens',
        'timeout_seconds', 'locked', 'credit_action_key', 'light_model_id', 'light_max_chars', 'cache_minutes',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fallback_model_ids' => 'array',
            'locked' => 'boolean',
            'max_output_tokens' => 'integer',
            'timeout_seconds' => 'integer',
            'light_model_id' => 'integer',
            'light_max_chars' => 'integer',
            'cache_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function primaryModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'primary_model_id');
    }

    /**
     * The model chain for a request of this size (AQ2): short requests go to the lighter model first when HQ has
     * set one, falling back to the usual chain.
     *
     * @return list<AiModel>
     */
    public function modelChainFor(int $promptChars): array
    {
        $chain = $this->modelChain();
        $lightId = $this->getAttribute('light_model_id');
        if ($lightId === null || (int) $this->getAttribute('light_max_chars') <= 0 || $promptChars > (int) $this->getAttribute('light_max_chars')) {
            return $chain;
        }
        $light = AiModel::query()->with('provider')->find((int) $lightId);

        return $light instanceof AiModel ? [$light, ...array_values(array_filter($chain, fn (AiModel $m): bool => $m->id !== $light->id))] : $chain;
    }

    /**
     * Primary model first, then fallbacks in order.
     *
     * @return list<AiModel>
     */
    public function modelChain(): array
    {
        $ids = array_values(array_unique([$this->primary_model_id, ...$this->fallback_model_ids]));
        $models = AiModel::query()->with('provider')->whereIn('id', $ids)->get()->keyBy('id');

        $chain = [];
        foreach ($ids as $id) {
            $model = $models->get($id);
            if ($model instanceof AiModel) {
                $chain[] = $model;
            }
        }

        return $chain;
    }
}
