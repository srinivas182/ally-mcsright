<?php

declare(strict_types=1);

namespace Modules\Insights;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last month with RightAlly, for owners (specs/insights/01, K3). */
final class RoiMail extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public readonly array $report) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->report;
        $mail = (new MailMessage)->subject('Your '.$r['label'].' with RightAlly: '.$r['hours_saved'].' hours saved')
            ->line('About **'.$r['hours_saved'].' hours** of work done for your team: '.$r['automations']['steps'].' automation steps, '
                .$r['ai_replies'].' AI replies to leads and '.$r['documents_read'].' documents read.')
            ->line($r['leads']['count'].' leads'.($r['leads']['median_minutes'] !== null ? ', answered in a median of '.$r['leads']['median_minutes'].' minutes' : '').'.')
            ->line($r['closings']['sides'].' closings, $'.number_format($r['closings']['gci_cents'] / 100).' in GCI.');
        if ($r['website'] !== null) {
            $mail->line('Your website brought '.$r['website']['leads'].' leads and '.$r['website']['closed'].' closings.');
        }

        return $mail->action('See the full report', url('/insights/roi?month='.$r['month']));
    }
}
