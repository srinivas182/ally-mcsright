<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $cap_period_id
 * @property int $calculation_id
 * @property int $company_dollar_cents
 * @property int $post_cap_fee_cents
 * @property string $reason
 */
final class CapEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'comp_cap_entries';

    protected $fillable = ['cap_period_id', 'calculation_id', 'company_dollar_cents', 'post_cap_fee_cents', 'reason'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
