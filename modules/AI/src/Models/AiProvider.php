<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Database\Factories\AiProviderFactory;
use Modules\Core\Support\Connections;

/**
 * An AI provider. It may only be used when active AND contractually confirmed as
 * no-training and zero-retention (ADR 0007).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $adapter
 * @property string $base_url
 * @property string|null $credential_config_key
 * @property bool $zero_retention_confirmed
 * @property bool $no_training_confirmed
 * @property string $status
 */
final class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'adapter', 'base_url', 'credential_config_key', 'zero_retention_confirmed',
        'no_training_confirmed', 'dpa_signed_at', 'status',
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
            'zero_retention_confirmed' => 'boolean',
            'no_training_confirmed' => 'boolean',
            'dpa_signed_at' => 'immutable_date',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->zero_retention_confirmed && $this->no_training_confirmed;
    }

    public function apiKey(): ?string
    {
        if ($this->credential_config_key === null) {
            return null;
        }

        $key = config($this->credential_config_key);

        return is_string($key) && $key !== '' ? $key : null;
    }

    protected static function newFactory(): AiProviderFactory
    {
        return AiProviderFactory::new();
    }
}
