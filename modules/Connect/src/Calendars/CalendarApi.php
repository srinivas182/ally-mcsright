<?php

declare(strict_types=1);

namespace Modules\Connect\Calendars;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar and Microsoft Graph, through their own APIs (specs/connect/02): busy times from the person's
 * primary calendar, and adding a booking to it.
 */
final class CalendarApi
{
    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    public static function busy(string $app, string $token, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($app === 'google_calendar') {
            $r = Http::withToken($token)->acceptJson()->timeout(8)->post('https://www.googleapis.com/calendar/v3/freeBusy',
                ['timeMin' => $from->utc()->toIso8601ZuluString(), 'timeMax' => $to->utc()->toIso8601ZuluString(), 'items' => [['id' => 'primary']]])->throw();
            $ranges = (array) $r->json('calendars.primary.busy', []);
        } else {
            $r = Http::withToken($token)->acceptJson()->timeout(8)->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])
                ->get('https://graph.microsoft.com/v1.0/me/calendarView', ['startDateTime' => $from->utc()->toIso8601ZuluString(), 'endDateTime' => $to->utc()->toIso8601ZuluString(),
                    '$select' => 'start,end,showAs', '$top' => 200])->throw();
            $ranges = [];
            foreach ((array) $r->json('value', []) as $e) {
                if (is_array($e) && ($e['showAs'] ?? 'busy') !== 'free') {
                    $ranges[] = ['start' => $e['start']['dateTime'] ?? null, 'end' => $e['end']['dateTime'] ?? null];
                }
            }
        }
        $out = [];
        foreach ($ranges as $b) {
            if (is_array($b) && is_string($b['start'] ?? null) && is_string($b['end'] ?? null)) {
                $out[] = [CarbonImmutable::parse($b['start'], 'UTC'), CarbonImmutable::parse($b['end'], 'UTC')];
            }
        }

        return $out;
    }

    public static function add(string $app, string $token, string $title, CarbonImmutable $start, CarbonImmutable $end, string $details): string
    {
        if ($app === 'google_calendar') {
            $r = Http::withToken($token)->acceptJson()->timeout(10)->post('https://www.googleapis.com/calendar/v3/calendars/primary/events', ['summary' => $title, 'description' => $details,
                'start' => ['dateTime' => $start->utc()->toIso8601ZuluString()], 'end' => ['dateTime' => $end->utc()->toIso8601ZuluString()],
                'reminders' => ['useDefault' => true]])->throw();
        } else {
            $r = Http::withToken($token)->acceptJson()->timeout(10)->post('https://graph.microsoft.com/v1.0/me/events', ['subject' => $title,
                'body' => ['contentType' => 'text', 'content' => $details], 'start' => ['dateTime' => $start->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $end->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC']])->throw();
        }

        return (string) $r->json('id', '');
    }
}
