<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You have used 80% of this month's credits" and "You are out of credits" (once each per month). */
final class CreditsLow extends Notification
{
    use Queueable;

    public function __construct(public readonly int $threshold, public readonly int $available, public readonly int $total) {}

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
            ->subject($this->threshold >= 100 ? 'Your brokerage is out of RightAlly Credits' : 'You have used '.$this->threshold.'% of this month\'s RightAlly Credits')
            ->line($this->threshold >= 100
                ? 'AI features that use credits (including the website assistant and the AI first responder) pause until you add more.'
                : number_format($this->available).' of '.number_format($this->total).' credits are left this month.')
            ->action('See credits', url('/settings/credits'))
            ->line('You can see who used credits on what, give credits to agents, and add more on the Credits page.');
    }
}
