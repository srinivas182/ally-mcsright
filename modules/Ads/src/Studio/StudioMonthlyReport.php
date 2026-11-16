<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last month's Ally Studio report by email: what went out, who saw it, what people did, the leads, the best post. */
final class StudioMonthlyReport extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $report StudioResults::month() */
    public function __construct(public readonly array $report) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = (array) $this->report['totals'];
        $best = $this->report['formats'][0] ?? null;
        $top = $this->report['top'][0] ?? null;
        $mail = (new MailMessage)->subject('Your Ally Studio report: '.$this->report['label'])
            ->line(number_format((int) $t['posts']).' posts went out in '.$this->report['label'].'.')
            ->line(number_format((int) $t['reach']).' people reached, '.number_format((int) $t['interactions']).' likes, comments, shares and saves'
                .($t['rate'] !== null ? ' ('.$t['rate'].'% of people reached)' : '').'.')
            ->line(number_format((int) $t['leads']).' '.((int) $t['leads'] === 1 ? 'lead' : 'leads').' came in through your posts\' links.');
        if (is_array($best) && $best['rate'] !== null) {
            $mail->line('Best format: '.ucfirst((string) $best['key']).'. Autopilot leans on what works once it has enough posts to learn from.');
        }
        if (is_array($top)) {
            $mail->line('Top post: "'.mb_substr((string) $top['text'], 0, 80).'" — '.number_format((int) $top['reach']).' reached.');
        }

        return $mail->action('See the full report', url('/studio/results?month='.$this->report['month']));
    }
}
