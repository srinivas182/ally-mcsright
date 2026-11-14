<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells HQ which credit prices no longer cover their real cost at the minimum margin (specs/billing/02). */
final class MarginAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<array{action: string, label: string, credits: int, uses: int, avg_cost_microcents: int, margin_pct: int, suggested_credits: int}>  $alerts
     */
    public function __construct(public readonly array $alerts, public readonly int $floor) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(count($this->alerts).' credit price(s) are below your '.$this->floor.'% minimum margin')
            ->line('Over the last 7 days these actions cost more than their credit price allows:');
        foreach ($this->alerts as $a) {
            $mail->line(sprintf('%s: %d credit(s), real average cost $%s per use, margin %d%% (suggested: %d credits, %s uses)',
                $a['label'], $a['credits'], number_format($a['avg_cost_microcents'] / 100_000_000, 4), $a['margin_pct'], $a['suggested_credits'], number_format($a['uses'])));
        }

        return $mail->action('Review credit prices', url('/hq/credits'))
            ->line('Common causes: a provider raised its prices, requests moved to a costlier fallback model, or uses got larger.');
    }
}
