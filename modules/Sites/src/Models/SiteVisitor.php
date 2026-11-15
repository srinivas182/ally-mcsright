<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * Someone browsing a brokerage website, known by a random id their browser keeps (no cookies), and by email once
 * they sign in or send an enquiry (specs/websites/07).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $site_id
 * @property string $vid
 * @property int|null $merged_into
 * @property string|null $email
 * @property string|null $name
 * @property CarbonImmutable|null $verified_at
 * @property int|null $contact_id
 * @property int|null $lead_id
 * @property string|null $first_referrer
 * @property string|null $landing_url
 * @property int $visits
 * @property int $events_today
 * @property CarbonImmutable|null $events_day
 * @property string|null $summary
 * @property bool $summary_ai
 * @property CarbonImmutable|null $summary_at
 * @property array<string, string>|null $alerts
 * @property array<string, mixed>|null $brief
 * @property CarbonImmutable|null $brief_at
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 */
final class SiteVisitor extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_visitors';

    protected $fillable = ['site_id', 'vid', 'merged_into', 'email', 'name', 'verified_at', 'contact_id', 'lead_id', 'first_referrer', 'landing_url', 'visits',
        'events_today', 'events_day', 'summary', 'summary_ai', 'summary_at', 'alerts', 'first_seen_at', 'last_seen_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'events_day' => 'immutable_date', 'summary_ai' => 'boolean', 'summary_at' => 'immutable_datetime', 'brief' => 'array', 'brief_at' => 'immutable_datetime',
            'alerts' => 'array', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
