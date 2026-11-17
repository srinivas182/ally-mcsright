<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * An agent's own words for the careers page (RC2), shown only while they agree.
 *
 * @property int $id
 * @property int $user_id
 * @property string $quote
 * @property bool $show_deals
 * @property CarbonImmutable $consented_at
 */
final class Story extends Model
{
    use BelongsToTenant;

    protected $table = 'rec_stories';

    protected $fillable = ['user_id', 'quote', 'show_deals', 'consented_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_deals' => 'boolean', 'consented_at' => 'immutable_datetime'];
    }
}
