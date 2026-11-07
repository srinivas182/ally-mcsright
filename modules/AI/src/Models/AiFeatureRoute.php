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
        'timeout_seconds', 'locked', 'credit_action_key',
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
