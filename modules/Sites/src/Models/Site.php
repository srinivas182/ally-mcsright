<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A brokerage (or, from 17C-3, agent) website.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $kind
 * @property int|null $user_id
 * @property string|null $slug
 * @property string $template
 * @property array<string, mixed> $brand
 * @property array<string, mixed> $info
 * @property array<string, mixed>|null $seo
 * @property bool $ai_crawlers
 * @property array{signup_after?: int, agents?: array<string, bool>, market_updates?: array{enabled?: bool, places?: list<string>}, ailly?: array<string, mixed>}|null $settings
 * @property array{main?: list<array{label: string, url: string}>, footer?: list<array{label: string, url: string}>}|null $menus
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 */
final class Site extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'sites';

    protected $fillable = ['kind', 'user_id', 'slug', 'template', 'brand', 'info', 'seo', 'ai_crawlers', 'settings', 'menus', 'status', 'published_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['brand' => 'array', 'info' => 'array', 'seo' => 'array', 'ai_crawlers' => 'boolean', 'settings' => 'array', 'menus' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<SitePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SitePage::class);
    }

    public function displayName(): string
    {
        return (string) ($this->info['name'] ?? 'Our brokerage');
    }
}
