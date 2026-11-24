<?php

declare(strict_types=1);

namespace Modules\AI;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A brokerage's AI cost jumped yesterday (AQ2). */
final class AiCostSpike extends Notification
{
    use Queueable;

    /** @param  list<array{tenant: string, yesterday_cents: int, usual_cents: int}>  $spikes */
    public function __construct(public readonly array $spikes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('AI cost jumped for '.count($this->spikes).(count($this->spikes) === 1 ? ' brokerage' : ' brokerages'))->line('Yesterday these brokerages used far more AI than usual:');
        foreach ($this->spikes as $s) {
            $mail->line(sprintf('%s: $%s yesterday (usually about $%s a day)', $s['tenant'], number_format($s['yesterday_cents'] / 100, 2), number_format($s['usual_cents'] / 100, 2)));
        }

        return $mail->action('See AI cost', url('/hq/ai/cost'))->line('Common causes: a new automation, a large import run through AI, or a loop. Credits still limit what each brokerage can spend.');
    }
}
