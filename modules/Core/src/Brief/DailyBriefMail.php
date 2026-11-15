<?php

declare(strict_types=1);

namespace Modules\Core\Brief;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The morning email of the daily brief (specs/core/09): the top items, each with why, and a link to the full list. */
final class DailyBriefMail extends Notification
{
    use Queueable;

    /** @param  list<array{title: string, why: string, url: string, urgency: int, stakes_cents: int|null}>  $items */
    public function __construct(public readonly array $items, public readonly string $firstName, public readonly string $brokerage, public readonly string $home) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $now = count(array_filter($this->items, fn (array $i): bool => $i['urgency'] === 3));
        $mail = (new MailMessage)->subject(($now > 0 ? "{$now} thing".($now === 1 ? '' : 's').' need you today' : 'Your day').' · '.$this->brokerage)
            ->greeting('Good morning, '.$this->firstName);
        foreach ($this->items as $n => $i) {
            $mail->line(($n + 1).'. **'.$i['title'].'**'.($i['stakes_cents'] !== null ? ' ($'.number_format(intdiv($i['stakes_cents'], 100)).' at stake)' : '').' — '.$i['why']);
        }

        return $mail->action('Open your brief', url($this->home))->line('Turn this email off any time from your brief.');
    }
}
