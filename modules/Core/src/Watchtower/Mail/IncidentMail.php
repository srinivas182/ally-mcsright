<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Watchtower\Incident;

/**
 * One problem, emailed to the people HQ chose (OB1): what broke and, when the AI has explained it, the root cause and
 * the fix. The full fix request is attached, ready to paste to the engineering team.
 */
final class IncidentMail extends Notification
{
    /** @param list<Incident> $during what else went wrong while RightAlly was down */
    public function __construct(public readonly Incident $incident, public readonly string $reason, public readonly string $fixRequest, public readonly string $link,
        public readonly array $during = [], public readonly ?int $downMinutes = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $i = $this->incident;
        $label = match ($this->reason) {
            'regression' => 'came back after a fix',
            'worse' => 'got more serious',
            'spike' => 'is happening much more',
            'recovered' => 'is working again',
            default => strtolower(Incident::SEVERITIES[$i->severity] ?? 'new'),
        };
        $mail = (new MailMessage)->subject("[RightAlly {$i->reference()}] ".mb_substr($i->title, 0, 120)." ({$label})");
        if ($this->reason === 'recovered') {
            $mail->line($this->downMinutes !== null ? "RightAlly was down for about {$this->downMinutes} minutes and is back up." : "{$i->title}: working again.");
            if ($this->during !== []) {
                $mail->line('Problems seen while it was down (usually the reason):');
                foreach ($this->during as $d) {
                    $mail->line("- {$d->reference()}: {$d->title}");
                }
            }

            return $mail->action('Open Watchtower', $this->link);
        }
        $mail->line("**{$i->title}**")
            ->line((Incident::KINDS[$i->kind] ?? $i->kind).' · '.$i->occurrences.' time'.($i->occurrences === 1 ? '' : 's')
                .($i->brokerages > 0 ? " · {$i->brokerages} brokerage".($i->brokerages === 1 ? '' : 's') : '').($i->location !== null ? " · {$i->location}" : ''));
        $analysis = (array) $i->analysis;
        if (($analysis['summary'] ?? '') !== '') {
            $mail->line('**What happened:** '.$analysis['summary']);
            if (($analysis['cause'] ?? '') !== '') {
                $mail->line('**Likely root cause:** '.$analysis['cause']);
            }
            if (($analysis['fix'] ?? '') !== '') {
                $mail->line('**Suggested fix:** '.$analysis['fix']);
            }
        }

        return $mail->action('Open in Watchtower', $this->link)
            ->line('The fix request is attached: paste it to the engineering team as it is. Personal data has been removed.')
            ->attachData($this->fixRequest, 'fix-request-'.$i->reference().'.md', ['mime' => 'text/markdown']);
    }
}
