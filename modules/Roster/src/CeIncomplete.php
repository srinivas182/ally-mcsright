<?php

declare(strict_types=1);

namespace Modules\Roster;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CeIncomplete extends Notification
{
    use Queueable;

    public function __construct(public readonly string $agent, public readonly string $state, public readonly string $done, public readonly string $required, public readonly CarbonImmutable $renewal) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->agent}: continuing education due before {$this->renewal->toFormattedDateString()}")
            ->line("{$this->agent} has {$this->done} of {$this->required} CE hours for the {$this->state} licence renewal on {$this->renewal->toFormattedDateString()}.")
            ->line('Log completed courses with the certificate so the renewal is not at risk.')
            ->action('Log a course', url('/roster/me'));
    }
}
