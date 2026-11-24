<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the brokerage's owners before and after their data moves to (or from) its own database (DS2). */
final class MoveNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $when, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->when === 'before'
            ? (new MailMessage)->subject('Your RightAlly data is moving to its own database')
                ->line('We are moving your brokerage\'s data to a database of its own. Everyone can keep working.')
                ->line("Near the end there is a short read-only window of about {$this->minutes} ".($this->minutes === 1 ? 'minute' : 'minutes').': pages open, but saving waits until it is over.')
                ->line('We will email you when it is done.')
            : (new MailMessage)->subject('Your RightAlly data has moved')
                ->line('The move is finished and every table was checked: same rows, same money totals.')
                ->line('Your previous copy is kept for 14 days in case anything needs to be switched back.');
    }
}
