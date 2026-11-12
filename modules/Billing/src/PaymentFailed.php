<?php

declare(strict_types=1);

namespace Modules\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly ?CarbonImmutable $graceUntil) {}

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
            ->subject('Your RightAlly payment did not go through')
            ->line('We could not take this month\'s payment. Your tools keep working until '.($this->graceUntil?->toFormattedDateString() ?? 'next week').'.')
            ->line('Nothing is deleted. Update your card to keep everything running.')
            ->action('Update my card', url('/plans'));
    }
}
