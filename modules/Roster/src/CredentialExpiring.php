<?php

declare(strict_types=1);

namespace Modules\Roster;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CredentialExpiring extends Notification
{
    use Queueable;

    public function __construct(public readonly string $agent, public readonly string $what, public readonly CarbonImmutable $expiresOn, public readonly int $days) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->days < 0 ? 'expired on '.$this->expiresOn->toFormattedDateString() : ($this->days === 0 ? 'expires today' : "expires in {$this->days} days ({$this->expiresOn->toFormattedDateString()})");

        return (new MailMessage)
            ->subject("{$this->agent}: {$this->what} {$when}")
            ->line("{$this->agent}'s {$this->what} {$when}.")
            ->line('New deals cannot go under contract without an active licence and, where required, E&O. Upload the renewal to the roster.')
            ->action('Open the roster', url('/roster'));
    }
}
