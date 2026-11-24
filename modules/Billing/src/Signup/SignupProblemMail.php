<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To HQ: a brokerage paid but could not be created automatically; set it up by hand. */
final class SignupProblemMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brokerage, public readonly string $ownerEmail, public readonly string $problem) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Signup needs a hand: '.$this->brokerage)
            ->line($this->brokerage.' paid, but RightAlly could not create the brokerage automatically: '.$this->problem)
            ->line('Create it from HQ › Customers › Brokerages and email the owner at '.$this->ownerEmail.'.')
            ->action('Open go-live', 'https://'.config('core.hq_host').'/hq/go-live');
    }
}
