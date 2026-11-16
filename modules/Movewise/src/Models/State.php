<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;

/**
 * A Movewise state, with its own switch, managing broker and legal checklist (specs/movewise/00 §2, §12).
 *
 * @property string $code
 * @property string $name
 * @property bool $on
 * @property int|null $partner_id
 * @property array<string, array{done: bool, note?: string|null, by?: int|null, at?: string|null}>|null $checklist
 * @property array<string, mixed>|null $pricing
 * @property CarbonImmutable|null $turned_on_at
 * @property int|null $turned_on_by
 * @property-read Partner|null $partner
 */
final class State extends Model
{
    protected $table = 'movewise_states';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    protected function casts(): array
    {
        return ['on' => 'boolean', 'checklist' => 'array', 'pricing' => 'array', 'turned_on_at' => 'immutable_datetime'];
    }
}
