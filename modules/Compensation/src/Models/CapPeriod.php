<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One agent's cap year across every office and team of the brokerage.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $agent_user_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int|null $cap_amount_cents
 * @property int $company_dollar_cents
 * @property int $post_cap_fees_cents
 * @property string $status
 */
final class CapPeriod extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'comp_cap_periods';

    protected $fillable = ['agent_user_id', 'starts_on', 'ends_on', 'cap_amount_cents', 'company_dollar_cents', 'post_cap_fees_cents', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'cap_amount_cents' => 'integer', 'company_dollar_cents' => 'integer', 'post_cap_fees_cents' => 'integer'];
    }
}
