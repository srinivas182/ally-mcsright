<?php

declare(strict_types=1);

namespace Modules\Outreach\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A handwritten card waiting for (or sent by) the handwriting partner (MK2, specs/crm/21).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $contact_id
 * @property string $occasion
 * @property string $message
 * @property string $to_name
 * @property string $to_line1
 * @property string|null $to_line2
 * @property string $to_city
 * @property string $to_state
 * @property string $to_zip
 * @property string $from_name
 * @property string $from_company
 * @property string $status
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 */
final class CardRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'outreach_card_requests';

    protected $fillable = ['user_id', 'contact_id', 'occasion', 'message', 'to_name', 'to_line1', 'to_line2', 'to_city', 'to_state', 'to_zip', 'from_name', 'from_company', 'status', 'sent_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
