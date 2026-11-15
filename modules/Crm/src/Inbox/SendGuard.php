<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Carbon\CarbonImmutable;
use libphonenumber\PhoneNumberToTimeZonesMapper;
use libphonenumber\PhoneNumberUtil;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Message;

/**
 * Checked before every text or email leaves (specs/grow/05). Sending is blocked with the reason, instead of a
 * fine later: texting consent (or a recent text from the person, which a one-to-one reply may answer), opt-outs
 * and Do Not Call, 8am–9pm in the person's own time zone (from their area code), and Fair Housing wording.
 * A guardrail, not legal advice.
 */
final class SendGuard
{
    /** A one-to-one reply is allowed this long after the person last texted. */
    public const REPLY_WINDOW_DAYS = 30;

    public function __construct(private readonly MessagingSettings $messaging, private readonly TenantContext $tenants) {}

    /**
     * @return list<string> reasons the message cannot be sent (empty: it can)
     */
    public function check(Contact $contact, string $channel, string $body): array
    {
        $reasons = [];
        if (trim($body) === '') {
            $reasons[] = 'Write the message.';
        }
        $flags = FairHousing::screen($body, 'messages', 'blocked', $channel === 'sms' ? 'Text' : 'Email');
        if ($flags !== []) {
            $reasons[] = 'Fair Housing: reword "'.implode('", "', $flags).'" (describe the home, not who should live there).';
        }

        if ($channel === 'sms') {
            if (! $this->messaging->smsFor($this->tenants->id())) {
                $reasons[] = 'Texting is not switched on for your brokerage yet (RightAlly HQ).';
            }
            if ($contact->primary_phone === null) {
                return [...$reasons, 'This contact has no mobile number.'];
            }
            $optedOut = $contact->sms_opt_out_at !== null && ($contact->sms_opt_in_at === null || $contact->sms_opt_in_at->lte($contact->sms_opt_out_at));
            $recentReply = $this->textedUsRecently($contact);
            if ($optedOut) {
                $reasons[] = 'They opted out of texts (replied STOP or asked not to be texted).';
            } elseif (! $contact->sms_opt_in && ! $recentReply) {
                $reasons[] = 'No texting consent yet. Email them, or text once they have texted you or given consent on a form.';
            }
            if ($contact->do_not_call && ! $recentReply) {
                $reasons[] = 'They are on Do Not Call.';
            }
            $quiet = self::quietHours($contact->primary_phone);
            if ($quiet !== null) {
                $reasons[] = $quiet;
            }
        } elseif ($channel === 'email') {
            if ($contact->primary_email === null) {
                $reasons[] = 'This contact has no email address.';
            }
            $last = ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'email')->latest('id')->value('action');
            if ($last === 'opt_out') {
                $reasons[] = 'They unsubscribed from email.';
            }
        } else {
            $reasons[] = 'Choose text or email.';
        }

        return $reasons;
    }

    /**
     * Null when it is between 8am and 9pm where the number is (every time zone the area code covers);
     * otherwise why not.
     */
    public static function quietHours(string $e164, ?CarbonImmutable $at = null): ?string
    {
        $at ??= CarbonImmutable::now();
        try {
            $zones = PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForNumber(PhoneNumberUtil::getInstance()->parse($e164, 'US'));
        } catch (\Throwable) {
            $zones = [];
        }
        $zones = array_values(array_filter($zones, static fn (string $z): bool => in_array($z, timezone_identifiers_list(), true)));
        if ($zones === [] || count($zones) > 6) {
            $zones = ['America/New_York', 'America/Los_Angeles']; // unknown or toll-free: respect both coasts
        }
        foreach ($zones as $zone) {
            $local = $at->setTimezone($zone);
            if ($local->hour < 8 || $local->hour >= 21) {
                return 'It is '.$local->format('g:i a').' where they are. Texts go between 8am and 9pm their time.';
            }
        }

        return null;
    }

    private function textedUsRecently(Contact $contact): bool
    {
        return Message::query()->where('contact_id', $contact->id)->where('channel', 'sms')->where('direction', 'in')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::REPLY_WINDOW_DAYS))->exists();
    }
}
