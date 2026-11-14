<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The automatic top-up (or a one-click buy) could not charge the saved card. */
final class CardNeedsAttention extends Notification
{
    use Queueable;

    public function __construct(public readonly string $error, public readonly bool $brokerage) {}

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
            ->subject('Your card needs attention to keep RightAlly credits topped up')
            ->line('We couldn\'t buy credits on your saved card: '.$this->error)
            ->line($this->brokerage
                ? 'Until credits are added, extras that use credits pause, including your website map once this month\'s included views are used.'
                : 'Until credits are added, AI features that use your own credits pause.')
            ->action('Update card or buy credits', url('/settings/credits#buy'))
            ->line('Buying a pack with a new card saves it for next time.');
    }
}
