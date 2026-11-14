<?php

declare(strict_types=1);

namespace Modules\Listings;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To HQ: an MLS feed has fallen behind. */
final class StaleFeedAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $mls, public readonly ?int $minutes, public readonly ?string $error) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("MLS feed behind: {$this->mls}")
            ->line($this->minutes === null ? "{$this->mls} has not synced successfully yet." : "{$this->mls} is {$this->minutes} minutes behind.")
            ->line($this->error !== null ? "Last error: {$this->error}" : 'No error was reported; the MLS may be rate-limiting us.')
            ->action('Open MLS connections', url('/hq/mls'));
    }
}
