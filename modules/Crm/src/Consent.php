<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\Prospector\DncRegistry;
use Modules\Crm\Prospector\Prospector;

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

    private const METHODS = ['web_form', 'signed', 'verbal', 'sms_keyword'];

    /** Where consent can come from (specs/grow/11). */
    public const SOURCES = ['web_form', 'chat', 'import', 'connected_app', 'agent', 'sms_keyword', 'email_link'];

    /**
     * @param  array{source?: string, ref?: string|null, evidence?: array<string, mixed>|null}|null  $proof  where it came from and what the person saw and sent
     */
    public function optInSms(Contact $contact, string $method, string $wording, ?User $by = null, ?Request $request = null, ?array $proof = null): Contact
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
        $this->event($contact, 'sms', 'opt_in', $method, trim($wording), $by, $request, $now, $proof ?? ['source' => match ($method) {
            'sms_keyword' => 'sms_keyword', 'web_form' => 'web_form', default => 'agent'
        }]);
        $contact->forceFill(['sms_opt_in' => true, 'sms_consent_text' => mb_substr(trim($wording), 0, 500), 'sms_consent_method' => $method, 'sms_opt_in_at' => $now])->save();

        return $contact;
    }

    /** @param  array{source?: string, ref?: string|null, evidence?: array<string, mixed>|null}|null  $proof */
    public function optOutSms(Contact $contact, string $method = 'agent', ?User $by = null, ?array $proof = null): Contact
    {
        $now = CarbonImmutable::now();
        $this->event($contact, 'sms', 'opt_out', $method, null, $by, null, $now, $proof ?? ['source' => $method === 'sms_keyword' ? 'sms_keyword' : 'agent']);
        $contact->forceFill(['sms_opt_in' => false, 'sms_opt_out_at' => $now])->save();

        return $contact;
    }

    /** @param  array{source?: string, ref?: string|null, evidence?: array<string, mixed>|null}|null  $proof */
    public function setEmail(Contact $contact, bool $optIn, string $method, ?User $by = null, ?array $proof = null): Contact
    {
        $now = CarbonImmutable::now();
        $this->event($contact, 'email', $optIn ? 'opt_in' : 'opt_out', $method, null, $by, null, $now, $proof ?? ['source' => 'agent']);
        $contact->forceFill(['email_opt_in' => $optIn, 'email_opt_in_at' => $optIn ? $now : null])->save();

        return $contact;
    }

    /** @param  array{source?: string, ref?: string|null, evidence?: array<string, mixed>|null}|null  $proof */
    public function setDoNotCall(Contact $contact, bool $doNotCall, ?User $by = null, ?array $proof = null): Contact
    {
        $this->event($contact, 'call', $doNotCall ? 'opt_out' : 'opt_in', 'agent', null, $by, null, CarbonImmutable::now(), $proof ?? ['source' => 'agent']);
        $contact->forceFill(['do_not_call' => $doNotCall])->save();

        return $contact;
    }

    /** Manual one-to-one texts from the agent's phone. */
    public function canText(Contact $contact): bool
    {
        return $contact->primary_phone !== null && $contact->sms_opt_in;
    }

    /**
     * Automated or AI texts (Lead-IQ follow-up): written consent only, not after an opt-out, and only with proof on
     * file (the recorded opt-in with its wording), so every automated text can be shown to be allowed.
     */
    public function canAutoText(Contact $contact): bool
    {
        return $this->canText($contact)
            && in_array($contact->sms_consent_method, self::WRITTEN, true)
            && ($contact->sms_opt_out_at === null || ($contact->sms_opt_in_at !== null && $contact->sms_opt_in_at->gt($contact->sms_opt_out_at)))
            && $this->proof($contact) !== null;
    }

    /**
     * Automated or AI voice calls (LQ1): the FCC treats AI-generated voices as "artificial" voices under the TCPA, so
     * these need prior express written consent that covers calls. Allowed only with written consent on record whose
     * wording mentions calls, no opt-out since, and not on Do Not Call.
     */
    public function canAutoCall(Contact $contact): bool
    {
        $proof = $this->canAutoText($contact) ? $this->proof($contact) : null;

        return $this->canCall($contact) && $proof !== null && stripos((string) $proof->consent_text, 'call') !== false;
    }

    /** The opt-in that allows texting now (the latest one, after any opt-out), or null when there is no proof. */
    public function proof(Contact $contact): ?ConsentEvent
    {
        $last = ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'sms')->latest('occurred_at')->latest('id')->first();

        return $last !== null && $last->action === 'opt_in' && $last->consent_text !== null && trim($last->consent_text) !== '' ? $last : null;
    }

    /**
     * Texting consent in a word: yes (written, with proof), verbal, no (opted out) or unknown (never given, e.g. an
     * imported contact).
     */
    public function status(Contact $contact): string
    {
        if ($contact->sms_opt_out_at !== null && ($contact->sms_opt_in_at === null || $contact->sms_opt_out_at->gte($contact->sms_opt_in_at))) {
            return 'no';
        }
        if (! $contact->sms_opt_in) {
            return 'unknown';
        }

        return in_array($contact->sms_consent_method, self::WRITTEN, true) && $this->proof($contact) !== null ? 'yes' : 'verbal';
    }

    /**
     * An opt-out made in a connected app (a "Do not text" flag or tag in the old CRM) stops texts, emails or calls
     * here too, recorded with the app as its source. Opt-ins never come across without proof.
     */
    public function fromConnectedApp(Contact $contact, string $app, bool $sms, bool $email, bool $call): void
    {
        $proof = ['source' => 'connected_app', 'ref' => $app, 'evidence' => ['app' => $app, 'sms' => $sms, 'email' => $email, 'call' => $call]];
        if ($sms && $contact->sms_opt_in) {
            $this->optOutSms($contact, 'connected_app', null, $proof);
        }
        if ($email && ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'email')->latest('id')->value('action') !== 'opt_out') {
            $this->setEmail($contact, false, 'connected_app', null, $proof);
        }
        if ($call && ! $contact->do_not_call) {
            $this->setDoNotCall($contact, true, null, $proof);
        }
    }

    public function canCall(Contact $contact): bool
    {
        if ($contact->primary_phone === null || $contact->do_not_call) {
            return false;
        }
        // A prospect who never answered (no prior relationship) may be called only when the brokerage's copy of the
        // Do Not Call Registry says the number is clear (LQ2, specs/grow/15).
        $prospector = app(Prospector::class);

        return ! $prospector->isCold($contact) || app(DncRegistry::class)->status($contact->primary_phone) === DncRegistry::CLEAR;
    }

    /** @param  array{source?: string, ref?: string|null, evidence?: array<string, mixed>|null}  $proof */
    private function event(Contact $contact, string $channel, string $action, string $method, ?string $text, ?User $by, ?Request $request, CarbonImmutable $at, array $proof = []): void
    {
        $source = in_array($proof['source'] ?? null, self::SOURCES, true) ? $proof['source'] : 'agent';
        $evidence = (string) json_encode(['wording' => $text, 'source' => $source, 'ref' => $proof['ref'] ?? null, 'ip' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null, 'at' => $at->toIso8601String()] + (array) ($proof['evidence'] ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ConsentEvent::query()->create([
            'contact_id' => $contact->id, 'channel' => $channel, 'action' => $action, 'method' => $method,
            'consent_text' => $text, 'source' => $source, 'source_ref' => isset($proof['ref']) ? mb_substr((string) $proof['ref'], 0, 300) : null,
            'evidence' => $evidence, 'evidence_sha256' => hash('sha256', $evidence), 'phone' => $channel === 'email' ? null : $contact->primary_phone,
            'ip' => $request?->ip(), 'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'user_id' => $by?->id, 'occurred_at' => $at,
        ]);
        ContactBrief::query()->where('contact_id', $contact->id)->update(['stale' => true]);
        app(AuditLogger::class)->record("crm.consent.{$channel}_{$action}", $contact, metadata: ['method' => $method], severity: 'notice');
    }
}
