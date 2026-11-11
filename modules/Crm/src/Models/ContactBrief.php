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
 * @property array<string, mixed> $content
 * @property bool $stale
 * @property string|null $ai_request_ulid
 * @property int $helpful
 * @property int $not_helpful
 * @property CarbonImmutable $generated_at
 */
final class ContactBrief extends Model
{
    use BelongsToTenant;

    protected $table = 'crm_contact_briefs';

    protected $fillable = ['contact_id', 'content', 'stale', 'ai_request_ulid', 'helpful', 'not_helpful', 'generated_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'stale' => 'boolean', 'generated_at' => 'immutable_datetime'];
    }
}
