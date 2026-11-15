<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One place a social post goes (a Facebook Page, an Instagram account, a LinkedIn profile, a Google location).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $post_id
 * @property int $connection_id
 * @property string $channel
 * @property string $target_id
 * @property string $name
 * @property string $status
 * @property string|null $external_id
 * @property string|null $url
 * @property string|null $error
 * @property CarbonImmutable|null $done_at
 */
final class SocialTarget extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'ads_social_targets';

    protected $fillable = ['post_id', 'connection_id', 'channel', 'target_id', 'name', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['done_at' => 'immutable_datetime'];
    }
}
