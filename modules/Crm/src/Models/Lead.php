<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One inquiry (specs/grow/04). A contact can have many leads; each is routed and timed on its own.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $contact_id
 * @property int|null $source_id
 * @property string|null $external_id
 * @property string $source
 * @property string $channel
 * @property string|null $referrer_host
 * @property array<string, string>|null $utm
 * @property string|null $landing_url
 * @property string|null $intent
 * @property string|null $zip
 * @property int|null $price_min
 * @property int|null $price_max
 * @property string|null $property
 * @property string|null $message
 * @property string|null $raw
 * @property string $status
 * @property bool $new_contact
 * @property int|null $rule_id
 * @property int|null $assigned_user_id
 * @property CarbonImmutable|null $assigned_at
 * @property list<int>|null $offered_to
 * @property list<int>|null $passed_over
 * @property int $passes
 * @property CarbonImmutable|null $responded_at
 * @property int|null $responded_by
 * @property int|null $response_seconds
 * @property CarbonImmutable $created_at
 * @property int|null $score
 * @property string|null $score_label
 * @property list<string>|null $score_reasons
 * @property CarbonImmutable|null $scored_at
 * @property CarbonImmutable|null $hot_alerted_at
 * @property CarbonImmutable|null $engaged_at
 * @property Contact|null $contact
 */
final class Lead extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const OPEN = ['assigned', 'offered'];

    protected $table = 'crm_leads';

    protected $fillable = ['contact_id', 'source_id', 'external_id', 'source', 'channel', 'referrer_host', 'utm', 'landing_url', 'intent', 'zip', 'price_min', 'price_max',
        'property', 'message', 'raw', 'status', 'new_contact', 'rule_id', 'assigned_user_id', 'assigned_at', 'offered_to', 'passed_over', 'passes', 'responded_at', 'responded_by', 'response_seconds', 'engaged_at', 'score', 'score_label', 'score_reasons', 'scored_at', 'hot_alerted_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['utm' => 'array', 'message' => 'encrypted', 'raw' => 'encrypted', 'new_contact' => 'boolean', 'offered_to' => 'array', 'passed_over' => 'array',
            'assigned_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'price_min' => 'integer', 'price_max' => 'integer',
            'engaged_at' => 'immutable_datetime', 'score_reasons' => 'array', 'scored_at' => 'immutable_datetime', 'hot_alerted_at' => 'immutable_datetime', 'score' => 'integer'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
