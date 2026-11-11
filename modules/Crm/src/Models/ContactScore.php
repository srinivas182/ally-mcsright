<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $buyer
 * @property int $seller
 * @property string $timeframe
 * @property list<string> $reasons
 * @property CarbonImmutable $scored_at
 */
final class ContactScore extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'crm_contact_scores';

    protected $fillable = ['contact_id', 'buyer', 'seller', 'timeframe', 'reasons', 'scored_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reasons' => 'array', 'scored_at' => 'immutable_datetime'];
    }
}
