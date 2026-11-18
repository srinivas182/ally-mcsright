<?php

declare(strict_types=1);

namespace Modules\Crm\Consent;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent as ConsentRules;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use stdClass;

/**
 * One-tap written texting consent (CN1, specs/grow/18). The agent emails the contact a request; the contact opens it,
 * sees the brokerage's name, their own number and the exact consent wording, and taps "Yes, you may text me". That
 * tap is their written (electronic) consent: it is recorded with the wording, the time, their IP address and browser,
 * the email it was sent to, and becomes the proof that automated and AI texts are allowed.
 *
 * Rules: a mobile number and an email are needed; never to someone who unsubscribed from email or already gave
 * written consent; one request per contact per 30 days; at most 300 a day per brokerage; links last 30 days. A "no"
 * is recorded and nothing changes.
 */
final class ConsentRequests
{
    public const LINK_DAYS = 30;

    public const AGAIN_DAYS = 30;

    public const DAILY = 300;

    public function __construct(private readonly TenantContext $tenants, private readonly ConsentRules $consent) {}

    /** Why this contact cannot be asked right now, or null when they can. */
    public function blocker(Contact $contact): ?string
    {
        if ($contact->primary_phone === null) {
            return 'Add their mobile number first.';
        }
        if ($contact->primary_email === null) {
            return 'Add their email address first.';
        }
        $lastEmail = ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'email')->latest('occurred_at')->latest('id')->value('action');
        if ($lastEmail === 'opt_out') {
            return 'They unsubscribed from email.';
        }
        if ($contact->sms_opt_out_at !== null && ($contact->sms_opt_in_at === null || $contact->sms_opt_out_at->gte($contact->sms_opt_in_at))
            && $contact->sms_opt_out_at->greaterThan(now()->subDays(self::AGAIN_DAYS))) {
            return 'They replied STOP recently. Wait at least 30 days before asking again.';
        }
        if ($this->consent->status($contact) === 'yes') {
            return 'They already gave written consent.';
        }
        $last = $this->lastSent($contact);
        if ($last !== null && $last->greaterThan(now()->subDays(self::AGAIN_DAYS))) {
            return 'Asked on '.$last->toFormattedDateString().'. You can ask again after '.$last->addDays(self::AGAIN_DAYS)->toFormattedDateString().'.';
        }

        return null;
    }

    public function lastSent(Contact $contact): ?CarbonImmutable
    {
        $at = DB::connection(Connections::cell())->table('crm_consent_requests')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)->max('sent_at');

        return $at !== null ? CarbonImmutable::parse((string) $at) : null;
    }

    public function send(Contact $contact, User $by): void
    {
        if (! app(ContactVisibility::class)->canSee($by, $contact)) {
            throw ValidationException::withMessages(['contact' => 'You cannot see this contact.']);
        }
        if (($why = $this->blocker($contact)) !== null) {
            throw ValidationException::withMessages(['consent' => $why]);
        }
        if ($this->sentToday() >= self::DAILY) {
            throw ValidationException::withMessages(['consent' => 'Your brokerage has sent '.self::DAILY.' requests today. Send more tomorrow.']);
        }
        $brokerage = (string) $this->tenants->current()->name;
        $wording = self::wording($brokerage);
        $token = Str::random(48);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('crm_consent_requests')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'contact_id' => $contact->id,
            'phone' => (string) $contact->primary_phone, 'email' => (string) $contact->primary_email, 'wording' => $wording, 'token_hash' => hash('sha256', $token),
            'sent_by' => $by->id, 'sent_at' => now(), 'expires_at' => now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        Notification::route('mail', (string) $contact->primary_email)->notify(new ConsentAskMail(trim($contact->first_name) !== '' ? $contact->first_name : 'there', $by->name, $brokerage,
            url('/consent/'.$token)));
        app(AuditLogger::class)->record('crm.consent.requested', $contact, metadata: ['request' => $ulid], label: $contact->name);
    }

    /**
     * Ask everyone in the list who can be asked (up to the day's limit). Returns how many were sent and skipped.
     *
     * @param  iterable<Contact>  $contacts
     * @return array{sent: int, skipped: int}
     */
    public function sendMany(iterable $contacts, User $by): array
    {
        $sent = 0;
        $skipped = 0;
        foreach ($contacts as $c) {
            if ($this->blocker($c) !== null || $this->sentToday() >= self::DAILY) {
                $skipped++;

                continue;
            }
            $this->send($c, $by);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    public function find(string $token): ?stdClass
    {
        /** @var stdClass|null */
        return DB::connection(Connections::cell())->table('crm_consent_requests')->where('tenant_id', $this->tenants->id())->where('token_hash', hash('sha256', $token))->first();
    }

    /** The contact's answer from the link. Returns 'yes', 'no', or 'expired'. */
    public function answer(string $token, bool $yes, Request $request): string
    {
        $req = $this->find($token);
        if ($req === null || CarbonImmutable::parse((string) $req->expires_at)->isPast()) {
            return 'expired';
        }
        if ($req->answer !== null) {
            return (string) $req->answer;
        }
        $contact = Contact::query()->find($req->contact_id);
        if (! $contact instanceof Contact || $contact->primary_phone === null) {
            return 'expired';
        }
        DB::connection(Connections::cell())->table('crm_consent_requests')->where('id', $req->id)->update(['answer' => $yes ? 'yes' : 'no', 'answered_at' => now(),
            'ip' => mb_substr((string) $request->ip(), 0, 45), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'updated_at' => now()]);
        if ($yes) {
            $this->consent->optInSms($contact, 'web_form', (string) $req->wording, null, $request, ['source' => 'email_link', 'ref' => (string) $req->ulid,
                'evidence' => ['sent_to' => $req->email, 'phone_shown' => $req->phone, 'sent_at' => $req->sent_at, 'button' => 'Yes, you may text me']]);
        }
        app(AuditLogger::class)->record('crm.consent.answered', $contact, metadata: ['request' => $req->ulid, 'answer' => $yes ? 'yes' : 'no'], label: $contact->name);

        return $yes ? 'yes' : 'no';
    }

    /** The exact consent wording, naming the brokerage. */
    public static function wording(string $brokerage): string
    {
        return mb_substr(str_replace('from the brokerage and its agents', 'from '.$brokerage.' and its agents', ConsentRules::DEFAULT_SMS_WORDING), 0, 600);
    }

    private function sentToday(): int
    {
        return DB::connection(Connections::cell())->table('crm_consent_requests')->where('tenant_id', $this->tenants->id())->where('sent_at', '>=', now()->startOfDay())->count();
    }
}
