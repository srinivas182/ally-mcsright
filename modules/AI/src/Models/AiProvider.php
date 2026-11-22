<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Carbon\CarbonImmutable;
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
 * @property bool $standard_retention_accepted HQ accepted the provider's short standard retention (AI2)
 * @property bool $no_training_confirmed
 * @property CarbonImmutable|null $dpa_signed_at
 * @property string $status
 * @property string|null $api_key encrypted; never sent to screens (AI1)
 * @property string|null $api_key_hint last 4 characters, to tell keys apart
 * @property CarbonImmutable|null $key_saved_at
 * @property CarbonImmutable|null $key_checked_at
 */
final class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'adapter', 'base_url', 'credential_config_key', 'zero_retention_confirmed',
        'standard_retention_accepted', 'no_training_confirmed', 'dpa_signed_at', 'status',
    ];

    /** @var list<string> */
    protected $hidden = ['api_key'];

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
            'standard_retention_accepted' => 'boolean',
            'no_training_confirmed' => 'boolean',
            'dpa_signed_at' => 'immutable_date',
            'api_key' => 'encrypted',
            'key_saved_at' => 'immutable_datetime',
            'key_checked_at' => 'immutable_datetime',
        ];
    }

    /** Active, never trains on customer data, and keeps nothing or only its short standard retention that HQ accepted (AI2). */
    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->no_training_confirmed && $this->retention() !== 'unconfirmed';
    }

    /** What the provider keeps: zero (signed zero retention), standard (short abuse-check retention HQ accepted) or unconfirmed. */
    public function retention(): string
    {
        return $this->zero_retention_confirmed ? 'zero' : ($this->standard_retention_accepted ? 'standard' : 'unconfirmed');
    }

    /** The key saved in HQ › AI console; otherwise the server's .env (ANTHROPIC_API_KEY …) as a fallback. */
    public function apiKey(): ?string
    {
        if (is_string($this->api_key) && $this->api_key !== '') {
            return $this->api_key;
        }

        return $this->serverKey();
    }

    /** Where the key in use comes from: hq (saved in the AI console), server (.env) or none. */
    public function keySource(): string
    {
        return match (true) {
            is_string($this->api_key) && $this->api_key !== '' => 'hq',
            $this->serverKey() !== null => 'server',
            default => 'none',
        };
    }

    private function serverKey(): ?string
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
