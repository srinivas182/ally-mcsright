<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $audience brokerage or agent
 * @property int $monthly_cents
 * @property int $yearly_cents
 * @property int $ailly_conversations
 * @property list<string> $features
 * @property bool $active
 */
final class Package extends Model
{
    use HasPublicUlid;

    protected $table = 'billing_packages';

    protected $attributes = ['audience' => 'brokerage'];

    protected $fillable = ['name', 'audience', 'monthly_cents', 'yearly_cents', 'ailly_conversations', 'features', 'active'];

    /**
     * Active packages for brokerages or for solo agents, cheapest first.
     *
     * @return Collection<int, self>
     */
    public static function offered(string $audience): Collection
    {
        return self::query()->where('active', true)->where('audience', $audience)->orderBy('monthly_cents')->get();
    }

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['features' => 'array', 'active' => 'boolean'];
    }
}
