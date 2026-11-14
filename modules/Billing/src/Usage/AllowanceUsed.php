<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You've used 80% (or all) of this month's included texts / call minutes / map views." */
final class AllowanceUsed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $what, public readonly int $percent, public readonly int $used, public readonly int $included,
        public readonly int $credits, public readonly int $per) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $price = $this->credits.' '.($this->credits === 1 ? 'credit' : 'credits').' per '.($this->per === 1 ? rtrim($this->what, 's') : $this->per.' '.$this->what);

        return (new MailMessage)
            ->subject($this->percent >= 100 ? 'You\'ve used this month\'s included '.$this->what : 'You\'ve used '.$this->percent.'% of this month\'s included '.$this->what)
            ->line(number_format($this->used).' of '.number_format($this->included).' included '.$this->what.' used this month.')
            ->line($this->percent >= 100
                ? 'Everything keeps working. From now until the 1st, extra '.$this->what.' use RightAlly Credits ('.$price.').'
                : 'After the included amount, extra '.$this->what.' use RightAlly Credits ('.$price.').')
            ->action('See usage and credits', url('/settings/credits'));
    }
}
