<?php

declare(strict_types=1);

namespace Modules\Listings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One MLS feed (HQ). Credentials are encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $ulid
 * @property string $key
 * @property string $name
 * @property list<string> $states
 * @property string $provider
 * @property string $base_url
 * @property string|null $originating_system
 * @property array<string, string>|null $credentials
 * @property string $status
 * @property int $sync_interval_minutes
 * @property int $rate_limit_per_hour
 * @property int|null $rules_profile_id
 * @property string $timezone
 * @property string|null $cursor
 * @property string|null $next_link
 * @property bool $full_sync_done
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_reconciled_at
 * @property CarbonImmutable|null $backoff_until
 * @property int $failures
 * @property string|null $last_error
 * @property RulesProfile|null $rulesProfile
 */
final class MlsSource extends Model
{
    use HasPublicUlid;

    public const PROVIDERS = ['mls_grid', 'trestle', 'bridge', 'direct_reso'];

    /**
     * Ready-made settings per provider (specs/websites/17): HQ only pastes the keys the MLS issued. A blank base URL
     * or token URL falls back to these; Bridge needs the dataset name at the end of its URL, so it has no default.
     *
     * @var array<string, array{base_url: string|null, token_url: string|null, auth: string, help: string}>
     */
    public const PRESETS = [
        'mls_grid' => ['base_url' => 'https://api.mlsgrid.com/v2', 'token_url' => null, 'auth' => 'token',
            'help' => 'Paste the access token from your MLS Grid account. Add the originating system name (for example "actris") so only that MLS is read.'],
        'trestle' => ['base_url' => 'https://api-trestle.corelogic.com/trestle/odata', 'token_url' => 'https://api-trestle.corelogic.com/trestle/oidc/connect/token', 'auth' => 'oauth',
            'help' => 'Paste the client ID and client secret from Trestle. The token address is filled in for you.'],
        'bridge' => ['base_url' => null, 'token_url' => null, 'auth' => 'token',
            'help' => 'Use the dataset address Bridge gave you (it ends with the dataset name) and the server token.'],
        'direct_reso' => ['base_url' => null, 'token_url' => null, 'auth' => 'token',
            'help' => 'Use the RESO Web API address and the token or client details from the MLS.'],
    ];

    protected $table = 'mls_sources';

    protected $fillable = ['key', 'name', 'states', 'provider', 'base_url', 'originating_system', 'credentials', 'status', 'sync_interval_minutes', 'rate_limit_per_hour',
        'rules_profile_id', 'timezone', 'cursor', 'next_link', 'full_sync_done', 'last_success_at', 'last_reconciled_at', 'backoff_until', 'failures', 'last_error'];

    protected $hidden = ['credentials'];

    public function getConnectionName(): ?string
    {
        return Connections::listings() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['states' => 'array', 'credentials' => 'encrypted:array', 'full_sync_done' => 'boolean', 'last_success_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime', 'backoff_until' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<RulesProfile, $this>
     */
    public function rulesProfile(): BelongsTo
    {
        return $this->belongsTo(RulesProfile::class);
    }

    /** Minutes since the last successful sync (null: never). */
    public function minutesBehind(): ?int
    {
        return $this->last_success_at !== null ? (int) $this->last_success_at->diffInMinutes(CarbonImmutable::now()) : null;
    }
}
