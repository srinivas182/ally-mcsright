<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Replies;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One comment or message on an agent's Facebook Page or Instagram, with its drafted reply (specs/grow/13, SA5c).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property int $connection_id
 * @property string $channel
 * @property string $kind
 * @property string $account
 * @property string $external_id
 * @property string|null $from_ref
 * @property string|null $from_name
 * @property string|null $post_ref
 * @property int|null $social_post_id
 * @property string $text
 * @property string|null $intent
 * @property string|null $draft
 * @property string $status
 * @property string|null $reason
 * @property string|null $reply_ref
 * @property int|null $lead_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $sent_at
 */
final class StudioReply extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_replies';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'user_id' => 'integer', 'connection_id' => 'integer'];
    }
}
