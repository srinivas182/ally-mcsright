<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Enums\Source;
use Modules\Crm\FairHousing;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\SignCode;

/**
 * Text-for-info sign codes (specs/grow/05): "Text OAK12 to (512) 555-0100" on a yard sign or flyer. The person
 * gets the home's details straight back (a reply to their own text), and becomes a lead for that listing's agent.
 * We text them nothing more without consent.
 */
final class SignCodes
{
    public function __construct(private readonly TenantContext $tenants, private readonly MessagingSettings $messaging) {}

    public function create(string $code, string $property, string $details, User $by): SignCode
    {
        $code = strtoupper(trim($code));
        $errors = [];
        if (preg_match('/^[A-Z][A-Z0-9]{2,11}$/', $code) !== 1 || in_array($code, ['STOP', 'START', 'HELP', 'INFO', 'YES', 'CANCEL', 'END', 'QUIT', 'UNSTOP', 'STOPALL', 'UNSUBSCRIBE', 'REVOKE', 'OPTOUT'], true)) {
            $errors['code'] = 'Use 3 to 12 letters and numbers, starting with a letter (not STOP, HELP or START).';
        } elseif (SignCode::query()->where('code', $code)->exists()) {
            $errors['code'] = 'That code is already used in your brokerage.';
        }
        if (trim($property) === '') {
            $errors['property'] = 'Enter the property address.';
        }
        if (trim($details) === '') {
            $errors['details'] = 'Write the details to text back (price, beds, baths, a link).';
        }
        if (($flags = FairHousing::flags($details)) !== []) {
            $errors['details'] = 'Fair Housing: reword "'.implode('", "', $flags).'".';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $sign = SignCode::query()->create(['code' => $code, 'user_id' => $by->id, 'property' => mb_substr(trim(strip_tags($property)), 0, 200),
            'details' => mb_substr(trim(strip_tags($details)), 0, 300)]);
        app(AuditLogger::class)->record('crm.sign_code.created', $sign, metadata: ['code' => $code]);

        return $sign;
    }

    public function match(string $keyword): ?SignCode
    {
        return $keyword === '' ? null : SignCode::query()->where('code', $keyword)->where('active', true)->first();
    }

    /** Sign codes need the brokerage's own number: a stranger's first text must reach the right brokerage. */
    public function number(): ?string
    {
        return $this->messaging->ownNumber($this->tenants->id());
    }

    /** Returns the text to send back. */
    public function texted(SignCode $sign, string $from, ?Contact $contact, string $body, ?string $providerId): string
    {
        $sign->increment('texts');
        $agent = User::query()->find($sign->user_id);
        $source = LeadSource::query()->firstOrCreate(['kind' => 'sms', 'source' => Source::Sign],
            ['name' => 'Text-for-info signs', 'token' => Str::random(40), 'active' => true]);
        try {
            $lead = app(LeadIntake::class)->receive($source, [
                'first_name' => $contact?->first_name ?: 'Texted '.$sign->code, 'last_name' => $contact !== null ? $contact->last_name : '', 'phone' => $from,
                'intent' => 'buy', 'property' => $sign->property, 'message' => mb_substr($body, 0, 200),
                'external_id' => $providerId,
            ], null, $agent?->status === UserStatus::Active ? $agent : null);
            $contact = $lead->contact;
        } catch (ValidationException $e) {
            report($e);
        }
        if ($contact !== null) {
            app(Inbox::class)->store($contact->refresh(), 'sms', $body, null, $providerId, false);
        }

        $reply = mb_substr("{$sign->property}: {$sign->details}".($agent !== null ? " — {$agent->first_name}, {$this->tenants->current()->name}" : ''), 0, 400).' Reply STOP to opt out.';
        if ($contact !== null) {
            $conversation = app(Inbox::class)->conversationFor($contact);
            Message::query()->create(['conversation_id' => $conversation->id, 'contact_id' => $contact->id, 'channel' => 'sms', 'direction' => 'out',
                'user_id' => null, 'body' => $reply, 'status' => 'sent']);
        }

        return $reply;
    }
}
