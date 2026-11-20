<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Watchtower\Incident;

/** The daily Watchtower report (OB1): new problems, what is still open, and any system that is failing. */
final class WatchtowerReportMail extends Notification
{
    /**
     * @param  list<Incident>  $new
     * @param  array<string, int>  $open
     * @param  list<array{name: string, label: string, ok: bool, message: string|null, checked_at: string, failing_since: string|null}>  $failing
     */
    public function __construct(public readonly array $new, public readonly array $open, public readonly array $failing, public readonly int $resolved, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $openCount = array_sum($this->open);
        $mail = (new MailMessage)->subject('RightAlly daily report: '.count($this->new).' new problem'.(count($this->new) === 1 ? '' : 's').", {$openCount} open")
            ->line('Open now: '.($this->open['critical'] ?? 0).' critical, '.($this->open['high'] ?? 0).' high, '.($this->open['normal'] ?? 0).' normal. '
                .$this->resolved.' resolved in the last day.');
        if ($this->failing !== []) {
            $mail->line('**Failing now:** '.implode('; ', array_map(fn (array $c): string => $c['label'].': '.($c['message'] ?? 'failed'), $this->failing)));
        }
        if ($this->new === []) {
            $mail->line('No new problems in the last day.');
        } else {
            $mail->line('New in the last day:');
            foreach ($this->new as $i) {
                $mail->line("- {$i->reference()} (".(Incident::SEVERITIES[$i->severity] ?? $i->severity)."): {$i->title}, {$i->occurrences}×");
            }
        }

        return $mail->action('Open Watchtower', $this->link);
    }
}
