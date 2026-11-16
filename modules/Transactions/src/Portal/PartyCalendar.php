<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * Calendar invites for everyone on a deal (specs/ai/02, §19): each person in the portal gets a private calendar feed
 * of the dates their role sees, in their language. Subscribed in Google, Outlook or Apple Calendar, it updates by
 * itself when the timeline changes. The link only shows dates and the street; the agent can replace it by removing
 * the person.
 */
final class PartyCalendar
{
    /** A new private feed link for one person (the old one stops working). */
    public function link(\stdClass $access): string
    {
        $token = Str::random(48);
        DB::connection(Connections::cell())->table('tx_portal_access')->where('id', $access->id)->update(['calendar_hash' => hash('sha256', $token), 'updated_at' => now()]);

        return url('/my-deal/calendar/'.$token.'.ics');
    }

    /** Emails everyone in the deal's portal their calendar link. */
    public function invite(Deal $deal, User $by): int
    {
        $n = 0;
        foreach (DB::connection(Connections::cell())->table('tx_portal_access')->where('deal_id', $deal->id)->whereNull('revoked_at')->get() as $a) {
            $locale = Copy::locale((string) $a->locale);
            $url = $this->link($a);
            Notification::route('mail', (string) $a->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.calendar_subject', ['street' => $deal->street]),
                [Copy::t($locale, 'mail.calendar_intro')], preg_replace('#^https?://#', 'webcal://', $url) ?? $url, (string) $by->email, 'mail.calendar_add'));
            $n++;
        }
        app(AuditLogger::class)->record('transactions.portal.calendars_sent', $deal, metadata: ['to' => $n], label: $deal->street);

        return $n;
    }

    /** The feed behind a link, or null. */
    public function feed(string $token): ?string
    {
        $a = DB::connection(Connections::cell())->table('tx_portal_access')->where('calendar_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        $deal = $a !== null ? Deal::query()->whereKey((int) $a->deal_id)->first() : null;
        if ($a === null || $deal === null) {
            return null;
        }
        $locale = Copy::locale((string) $a->locale);
        $esc = fn (string $s): string => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $s);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RightAlly//Deal portal//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$esc($deal->street)];
        $stamp = gmdate('Ymd\THis\Z');
        foreach (PortalView::dates($deal, (string) $a->role, $locale) as $i => $d) {
            $title = $d['label'].' — '.$deal->street;
            array_push($lines, 'BEGIN:VEVENT', 'UID:portal-'.$a->id.'-'.md5($d['label']).'@rightally', 'DTSTAMP:'.$stamp, 'DTSTART;VALUE=DATE:'.str_replace('-', '', $d['date']),
                'DTEND;VALUE=DATE:'.CarbonImmutable::parse($d['date'])->addDay()->format('Ymd'), 'SUMMARY:'.$esc($title), 'URL:'.url('/my-deal/'.$a->ulid), 'END:VEVENT');
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }
}
