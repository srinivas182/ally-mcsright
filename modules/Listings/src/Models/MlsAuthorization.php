<?php

declare(strict_types=1);

namespace Modules\Listings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A brokerage's MLS licence (ADR 0012): requested by the brokerage, approved by HQ, checked on every query.
 * A platform record on the control connection.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $mls_source_id
 * @property string $broker_mls_id
 * @property list<string>|null $office_mls_ids
 * @property list<string> $scopes
 * @property string|null $agreement_ref
 * @property string $status
 * @property CarbonImmutable|null $expires_on
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_note
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
final class MlsAuthorization extends Model
{
    use HasPublicUlid;

    public const SCOPES = ['idx', 'vow', 'back_office', 'agent_production'];

    protected $table = 'mls_authorizations';

    protected $fillable = ['tenant_id', 'mls_source_id', 'broker_mls_id', 'office_mls_ids', 'scopes', 'agreement_ref', 'status', 'expires_on', 'requested_by', 'decided_by',
        'decision_note', 'activated_at', 'revoked_at'];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['office_mls_ids' => 'array', 'scopes' => 'array', 'expires_on' => 'immutable_date', 'activated_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
