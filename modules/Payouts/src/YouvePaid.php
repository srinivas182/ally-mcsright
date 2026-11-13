<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class YouvePaid extends Notification
{
    use Queueable;

    public function __construct(public readonly int $cents, public readonly string $method, public readonly CarbonImmutable $on,
        public readonly int $commissionCents, public readonly int $revenueShareCents, public readonly ?int $checkNumber) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $money = fn (int $c): string => '$'.number_format($c / 100, 2);
        $how = $this->method === 'ach' ? 'by direct deposit' : 'by check'.($this->checkNumber !== null ? " #{$this->checkNumber}" : '');

        return (new MailMessage)
            ->subject("You've been paid {$money($this->cents)}")
            ->line("{$money($this->cents)} is on its way {$how}, dated {$this->on->toFormattedDateString()}.")
            ->line("Commission {$money($this->commissionCents)} · Revenue share {$money($this->revenueShareCents)}.")
            ->action('See my money', url('/my-money'));
    }
}
