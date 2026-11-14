<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Crm\Enums\Source;

/**
 * A way leads arrive (specs/grow/04): an API key, a hosted website form or a portal-email forwarding address.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $kind
 * @property Source $source
 * @property string $token
 * @property string|null $key_hash
 * @property string|null $key_last4
 * @property string|null $consent_wording
 * @property string|null $heading
 * @property bool $active
 * @property CarbonImmutable|null $last_lead_at
 */
final class LeadSource extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const KINDS = ['api', 'form', 'email'];

    protected $table = 'crm_lead_sources';

    protected $fillable = ['name', 'kind', 'source', 'token', 'key_hash', 'key_last4', 'consent_wording', 'heading', 'active', 'last_lead_at', 'created_by'];

    protected $hidden = ['key_hash', 'token'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source' => Source::class, 'active' => 'boolean', 'last_lead_at' => 'immutable_datetime'];
    }
}
