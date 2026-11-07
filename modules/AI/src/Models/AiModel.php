<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Database\Factories\AiModelFactory;
use Modules\Core\Support\Connections;

/**
 * A model offered by a provider, with its real prices in cents per million tokens.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $model_id
 * @property string $display_name
 * @property list<string> $capabilities
 * @property int $input_cost_per_mtok_cents
 * @property int $output_cost_per_mtok_cents
 * @property int $cache_read_cost_per_mtok_cents
 * @property string $status
 * @property AiProvider $provider
 */
final class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    protected $fillable = [
        'provider_id', 'model_id', 'display_name', 'capabilities', 'context_window',
        'input_cost_per_mtok_cents', 'output_cost_per_mtok_cents', 'cache_read_cost_per_mtok_cents', 'status',
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
            'capabilities' => 'array',
            'input_cost_per_mtok_cents' => 'integer',
            'output_cost_per_mtok_cents' => 'integer',
            'cache_read_cost_per_mtok_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * Exact cost in micro-cents (millionths of a cent): tokens × cents per million tokens.
     */
    public function costMicrocents(int $tokensIn, int $tokensOut, int $cacheReadTokens = 0): int
    {
        return $tokensIn * $this->input_cost_per_mtok_cents
            + $tokensOut * $this->output_cost_per_mtok_cents
            + $cacheReadTokens * $this->cache_read_cost_per_mtok_cents;
    }

    protected static function newFactory(): AiModelFactory
    {
        return AiModelFactory::new();
    }
}
