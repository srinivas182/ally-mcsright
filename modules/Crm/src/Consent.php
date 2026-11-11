<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;

/**
 * Texting, email and calling consent (TCPA / CAN-SPAM), recorded as append-only evidence.
 *
 * - Texting opt-in needs the exact consent wording and how it was given.
 * - Automated or AI texts (Lead-IQ) need WRITTEN consent (web form or signed), never verbal, and
 *   never after an opt-out unless a newer written opt-in exists.
 * - Opt-outs and Do Not Call take effect immediately everywhere.
 * - Imported contacts never receive consent automatically.
 */
final class Consent
{
    public const DEFAULT_SMS_WORDING = 'I agree to receive calls and text messages, including automated and AI-assisted messages, from the brokerage and its agents about real estate at this number. Consent is not a condition of purchase. Message and data rates may apply. Reply STOP to opt out.';

    private const WRITTEN = ['web_form', 'signed'];

    private const METHODS = ['web_form', 'signed', 'verbal'];

    public function optInSms(Contact $contact, string $method, string $wording, ?User $by = null, ?Request $request = null): Contact
    {
        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'How was consent given: web form, signed or verbal?']);
        }
        if (trim($wording) === '') {
            throw ValidationException::withMessages(['wording' => 'Record the exact consent wording.']);
        }
        if ($contact->primary_phone === null) {
            throw ValidationException::withMessages(['phone' => 'Add a mobile number first.']);
        }

        $now = CarbonImmutable::now();
        $this->event($contact, 'sms', 'opt_in', $method, trim($wording), $by, $request, $now);
        $contact->forceFill(['sms_opt_in' => true, 'sms_consent_text' => mb_substr(trim($wording), 0, 500), 'sms_consent_method' => $method, 'sms_opt_in_at' => $now])->save();

        return $contact;
    }

    public function optOutSms(Contact $contact, string $method = 'agent', ?User $by = null): Contact
    {
        $now = CarbonImmutable::now();
        $this->event($contact, 'sms', 'opt_out', $method, null, $by, null, $now);
        $contact->forceFill(['sms_opt_in' => false, 'sms_opt_out_at' => $now])->save();

        return $contact;
    }

    public function setEmail(Contact $contact, bool $optIn, string $method, ?User $by = null): Contact
    {
        $now = CarbonImmutable::now();
        $this->event($contact, 'email', $optIn ? 'opt_in' : 'opt_out', $method, null, $by, null, $now);
        $contact->forceFill(['email_opt_in' => $optIn, 'email_opt_in_at' => $optIn ? $now : null])->save();

        return $contact;
    }

    public function setDoNotCall(Contact $contact, bool $doNotCall, ?User $by = null): Contact
    {
        $this->event($contact, 'call', $doNotCall ? 'opt_out' : 'opt_in', 'agent', null, $by, null, CarbonImmutable::now());
        $contact->forceFill(['do_not_call' => $doNotCall])->save();

        return $contact;
    }

    /** Manual one-to-one texts from the agent's phone. */
    public function canText(Contact $contact): bool
    {
        return $contact->primary_phone !== null && $contact->sms_opt_in;
    }

    /** Automated or AI texts (Lead-IQ follow-up): written consent only, not after an opt-out. */
    public function canAutoText(Contact $contact): bool
    {
        return $this->canText($contact)
            && in_array($contact->sms_consent_method, self::WRITTEN, true)
            && ($contact->sms_opt_out_at === null || ($contact->sms_opt_in_at !== null && $contact->sms_opt_in_at->gt($contact->sms_opt_out_at)));
    }

    public function canCall(Contact $contact): bool
    {
        return $contact->primary_phone !== null && ! $contact->do_not_call;
    }

    private function event(Contact $contact, string $channel, string $action, string $method, ?string $text, ?User $by, ?Request $request, CarbonImmutable $at): void
    {
        ConsentEvent::query()->create([
            'contact_id' => $contact->id, 'channel' => $channel, 'action' => $action, 'method' => $method,
            'consent_text' => $text, 'phone' => $channel === 'email' ? null : $contact->primary_phone,
            'ip' => $request?->ip(), 'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'user_id' => $by?->id, 'occurred_at' => $at,
        ]);
    }
}
