<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;

/**
 * Every lead comes in here, whatever the source (specs/grow/04): it is matched to an existing contact by email
 * or phone (or a company contact is created), attributed to a channel, recorded with any written consent, and
 * routed. Callers never learn whether the contact already existed.
 */
final class LeadIntake
{
    public function __construct(private readonly Timeline $timeline, private readonly Consent $consent, private readonly LeadRouting $routing) {}

    /**
     * @param  array<string, mixed>  $in
     */
    public function receive(LeadSource $source, array $in, ?Request $request = null, ?User $assignTo = null): Lead
    {
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 254) : null;
        $phone = trim((string) ($in['phone'] ?? '')) !== '' ? ManageContacts::e164((string) $in['phone']) : null;
        $first = mb_substr(trim(strip_tags((string) ($in['first_name'] ?? ''))), 0, 80);
        $last = mb_substr(trim(strip_tags((string) ($in['last_name'] ?? ''))), 0, 80);
        if ($first === '' && $last === '' && trim((string) ($in['name'] ?? '')) !== '') {
            $parts = preg_split('/\s+/', trim(strip_tags((string) $in['name']))) ?: [];
            $first = mb_substr((string) array_shift($parts), 0, 80);
            $last = mb_substr(implode(' ', $parts), 0, 80);
        }

        $errors = [];
        if ($first === '' && $last === '') {
            $errors['first_name'] = 'Enter a name.';
        }
        if ($email === null && $phone === null) {
            $errors['email'] = 'Enter an email address or a US phone number.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $externalId = isset($in['external_id']) && trim((string) $in['external_id']) !== '' ? mb_substr(trim((string) $in['external_id']), 0, 100) : null;
        if ($externalId !== null && ($existing = Lead::query()->where('source_id', $source->id)->where('external_id', $externalId)->first()) !== null) {
            return $existing; // a retry (Zapier, webhooks): never a second lead
        }

        $sourceValue = (Source::tryFrom((string) ($in['source'] ?? '')) ?? $source->source)->value;
        $landing = isset($in['landing_url']) ? mb_substr(trim((string) $in['landing_url']), 0, 500) : null;
        $referrer = trim((string) ($in['referrer'] ?? ''));
        $utm = Attribution::utm($in, $landing);
        $intent = in_array($in['intent'] ?? null, ['buy', 'sell', 'rent'], true) ? (string) $in['intent'] : null;
        $zip = preg_match('/^\d{5}/', trim((string) ($in['zip'] ?? '')), $z) === 1 ? $z[0] : null;
        $price = static fn ($v): ?int => is_numeric(str_replace([',', '$'], '', (string) $v)) && (int) str_replace([',', '$'], '', (string) $v) > 0 ? min(4_000_000_000, (int) str_replace([',', '$'], '', (string) $v)) : null;
        $message = mb_substr(trim(strip_tags((string) ($in['message'] ?? ''))), 0, 4000);
        $property = mb_substr(trim(strip_tags((string) ($in['property'] ?? ''))), 0, 200);

        $run = fn (): Lead => DB::connection((new Lead)->getConnectionName())->transaction(function () use ($source, $email, $phone, $first, $last, $externalId, $sourceValue, $landing, $referrer, $utm, $intent, $zip, $price, $in, $message, $property, $request): Lead {
            $contact = Contact::query()
                ->where(fn ($q) => $q->when($email !== null, fn ($q) => $q->orWhere('primary_email', $email))->when($phone !== null, fn ($q) => $q->orWhere('primary_phone', $phone)))
                ->orderBy('id')->first();
            $isNew = $contact === null;
            if ($contact === null) {
                $contact = Contact::query()->create([
                    'type' => 'person', 'first_name' => $first, 'last_name' => $last,
                    'emails' => $email !== null ? [['address' => $email, 'label' => 'personal', 'primary' => true]] : [],
                    'phones' => $phone !== null ? [['e164' => $phone, 'label' => 'mobile', 'primary' => true, 'can_text' => true]] : [],
                    'origin' => Origin::Company, 'source' => Source::from($sourceValue), 'source_detail' => mb_substr($source->name, 0, 150),
                    'lifecycle' => Lifecycle::Lead, 'owner_user_id' => null,
                ]);
            }

            try {
                $lead = Lead::query()->create([
                    'contact_id' => $contact->id, 'source_id' => $source->id, 'external_id' => $externalId, 'source' => $sourceValue,
                    'channel' => Attribution::channel($referrer, $utm, $sourceValue), 'referrer_host' => Attribution::host($referrer) !== '' ? mb_substr(Attribution::host($referrer), 0, 120) : null,
                    'utm' => $utm !== [] ? $utm : null, 'landing_url' => $landing !== '' ? $landing : null, 'intent' => $intent, 'zip' => $zip,
                    'price_min' => $price($in['price_min'] ?? $in['price'] ?? null), 'price_max' => $price($in['price_max'] ?? $in['price'] ?? null),
                    'property' => $property !== '' ? $property : null, 'message' => $message !== '' ? $message : null, 'status' => 'new', 'new_contact' => $isNew,
                ]);
            } catch (QueryException $e) {
                // Two copies of the same retry at once.
                if ($externalId !== null && ($dupe = Lead::query()->where('source_id', $source->id)->where('external_id', $externalId)->first()) !== null) {
                    return $dupe;
                }
                throw $e;
            }

            $what = ucfirst(str_replace('_', ' ', $sourceValue)).' lead'.($intent !== null ? " ({$intent})" : '').($property !== '' ? ": {$property}" : '');
            $this->timeline->system($contact, $isNew ? "New {$what}".($message !== '' ? " — \"{$message}\"" : '') : "New inquiry from this contact. {$what}".($message !== '' ? " — \"{$message}\"" : ''));

            $wording = trim((string) ($in['consent_text'] ?? ''));
            if ($phone !== null && ! empty($in['sms_consent']) && $wording !== '' && $contact->refresh()->primary_phone === $phone) {
                $this->consent->optInSms($contact, 'web_form', mb_substr($wording, 0, 500), null, $request);
            }

            $source->forceFill(['last_lead_at' => CarbonImmutable::now()])->save();

            return $lead;
        }, 3);

        /** @var Lead $lead */
        $lead = app(AuditLogger::class)->asAutomation('lead_intake', null, 'Receive leads from '.$source->name, $run);
        if ($lead->status === 'new' && $lead->wasRecentlyCreated) {
            // A sign code's lead goes to that listing's agent, unless the person already has an agent.
            if ($assignTo !== null && $lead->contact?->owner_user_id === null) {
                $this->routing->assign($lead, $assignTo, 'their sign');
            } else {
                $this->routing->route($lead);
            }
            app(AiResponder::class)->leadReceived($lead->refresh());
        }

        return $lead->refresh();
    }
}
