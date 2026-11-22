<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SU2: to HQ staff: someone started signing up a day ago and is still stuck. */
final class SignupStuckMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brokerage, public readonly string $name, public readonly string $email, public readonly string $where) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Sign-up stuck: '.$this->brokerage)
            ->line($this->name.' ('.$this->email.') started signing up '.$this->brokerage.' a day ago and has not finished.')
            ->line('Where they stopped: '.$this->where.'. They have had two reminders.')
            ->action('Open Sign-ups', 'https://'.config('core.hq_host').'/hq/signups')
            ->line('A short call often helps.');
    }
}
