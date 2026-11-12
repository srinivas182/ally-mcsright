<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BudgetWarning extends Notification
{
    use Queueable;

    public function __construct(public readonly string $percent, public readonly int $paidCents, public readonly int $budgetCents) {}

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

        return (new MailMessage)
            ->subject('Revenue share has reached 80% of this year\'s budget')
            ->line("Revenue share paid this year: {$money($this->paidCents)} of a {$money($this->budgetCents)} budget ({$this->percent}% of company dollar).")
            ->line('When the budget is reached, further revenue share this year is recorded as missed, not paid. You can raise the budget or review the plan in the simulator.')
            ->action('Review revenue share', url('/compensation/plans'));
    }
}
