<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells HQ a brokerage passed its website map allowance, so HQ can choose to cover it or suggest a bigger package. */
final class MapAllowanceReached extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brokerage, public readonly string $tenantUlid, public readonly int $allowance, public readonly string $month) {}

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
            ->subject($this->brokerage.' passed its website map allowance')
            ->line($this->brokerage.' has used its '.number_format($this->allowance).' included map views for '.$this->month.'. Extra views now use the brokerage\'s credits.')
            ->line('You can let RightAlly cover their extra map views, or suggest a bigger package.')
            ->action('Open RightAlly credits', url('/hq/credits'));
    }
}
