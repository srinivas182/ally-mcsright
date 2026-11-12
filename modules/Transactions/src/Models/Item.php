<?php

declare(strict_types=1);

namespace Modules\Transactions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $deal_id
 * @property string $label
 * @property bool $required
 * @property string $stage
 * @property int $sort
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $fix_reason
 * @property int|null $reviewed_by
 */
final class Item extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'tx_items';

    protected $fillable = ['deal_id', 'label', 'required', 'stage', 'sort', 'status', 'submitted_at', 'fix_reason', 'reviewed_by', 'reviewed_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }
}
