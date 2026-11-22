<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SU1: "you didn't quite finish": a link back to the filled-in form, and a way to stop reminders. */
final class SignupReminderMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $brokerage, public readonly string $resumeUrl,
        public readonly string $stopUrl, public readonly int $number) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = trim((string) strtok($this->name, ' '));

        return (new MailMessage)->subject($this->number === 1 ? 'Finish setting up RightAlly for '.$this->brokerage : 'Your RightAlly is still waiting for you')
            ->greeting('Hello'.($first !== '' ? ' '.$first : '').',')
            ->line('You started signing up '.$this->brokerage.' for RightAlly but didn\'t get to the payment. Nothing was charged.')
            ->action('Carry on where you left off', $this->resumeUrl)
            ->line('Everything you typed is filled in for you. It takes about a minute to finish.')
            ->line('Questions, or rather talk to someone first? Just reply to this email.')
            ->line('Don\'t want reminders? [Stop these emails]('.$this->stopUrl.').');
    }
}
