<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;

/**
 * A person or company the brokerage or an agent works with. Always queried through
 * ContactVisibility (specs/crm/01): never list contacts without it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $type
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company_name
 * @property list<array{address: string, label: string, primary: bool}> $emails
 * @property list<array{e164: string, label: string, primary: bool, can_text: bool}> $phones
 * @property string|null $primary_email
 * @property string|null $primary_phone
 * @property string $search_name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip
 * @property CarbonImmutable|null $birthday
 * @property Origin $origin
 * @property Source $source
 * @property string|null $source_detail
 * @property Lifecycle $lifecycle
 * @property int|null $owner_user_id
 * @property int|null $team_id
 * @property int|null $office_id
 * @property CarbonImmutable|null $last_activity_at
 * @property bool $email_opt_in
 * @property bool $sms_opt_in
 * @property bool $do_not_call
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read string $name
 */
final class Contact extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;
    use SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'type', 'first_name', 'last_name', 'company_name', 'emails', 'phones', 'address_line1', 'address_line2',
        'city', 'state', 'zip', 'birthday', 'origin', 'source', 'source_detail', 'lifecycle', 'owner_user_id',
        'team_id', 'office_id', 'created_by',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        // Normalised columns used for search and duplicate detection.
        self::saving(function (self $contact): void {
            $emails = $contact->emails;
            $phones = $contact->phones;
            $primaryEmail = collect($emails)->firstWhere('primary', true)['address'] ?? ($emails[0]['address'] ?? null);
            $primaryPhone = collect($phones)->firstWhere('primary', true)['e164'] ?? ($phones[0]['e164'] ?? null);
            $contact->primary_email = $primaryEmail !== null ? mb_strtolower($primaryEmail) : null;
            $contact->primary_phone = $primaryPhone;
            $contact->search_name = mb_strtolower(trim($contact->first_name.' '.$contact->last_name.' '.($contact->company_name ?? '')));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emails' => 'array', 'phones' => 'array', 'birthday' => 'immutable_date',
            'origin' => Origin::class, 'source' => Source::class, 'lifecycle' => Lifecycle::class,
            'last_activity_at' => 'immutable_datetime', 'email_opt_in' => 'boolean', 'sms_opt_in' => 'boolean',
            'do_not_call' => 'boolean', 'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name) ?: (string) $this->company_name);
    }

    /**
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'crm_household_members', 'contact_id', 'household_id')->withPivot('role');
    }
}
