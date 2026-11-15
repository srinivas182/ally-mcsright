<?php

declare(strict_types=1);

namespace Modules\Sites\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The visitor's confirmation of a call, showing or listing appointment booked through AIlly, with a calendar invite
 * (.ics) so it lands in their calendar. It names the agent and how to reach them, nothing else about the brokerage.
 */
final class BookingConfirmedMail extends Mailable
{
    public function __construct(
        public readonly string $kind,
        public readonly CarbonImmutable $start,
        public readonly string $timezone,
        public readonly string $agentName,
        public readonly string $siteName,
        public readonly ?string $home,
        public readonly string $agentPhone,
    ) {}

    public function title(): string
    {
        return match ($this->kind) {
            'showing' => 'Showing'.($this->home !== null ? ': '.$this->home : ''),
            'listing' => 'Home value appointment with '.$this->agentName,
            default => 'Call with '.$this->agentName,
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Booked: '.$this->title().' — '.$this->start->setTimezone($this->timezone)->format('D M j, g:i A T'));
    }

    public function content(): Content
    {
        return new Content(view: 'sites::mail.booking', with: ['title' => $this->title(), 'when' => $this->start->setTimezone($this->timezone)->format('l, F j \a\t g:i A T'),
            'agentName' => $this->agentName, 'agentPhone' => $this->agentPhone, 'siteName' => $this->siteName, 'kind' => $this->kind]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->ics(), 'booking.ics')->withMime('text/calendar; charset=utf-8; method=PUBLISH')];
    }

    public function ics(): string
    {
        $esc = static fn (string $s): string => str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $s);
        $utc = static fn (CarbonImmutable $t): string => $t->utc()->format('Ymd\THis\Z');
        $end = $this->start->addMinutes($this->kind === 'call' ? 30 : 60);
        $desc = 'With '.$this->agentName.($this->agentPhone !== '' ? ' ('.$this->agentPhone.')' : '').' from '.$this->siteName.'.';

        return implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RightAlly//Booking//EN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:'.Str::uuid()->toString().'@rightally', 'DTSTAMP:'.$utc(CarbonImmutable::now()), 'DTSTART:'.$utc($this->start), 'DTEND:'.$utc($end),
            'SUMMARY:'.$esc($this->title()), 'DESCRIPTION:'.$esc($desc), 'END:VEVENT', 'END:VCALENDAR'])."\r\n";
    }
}
