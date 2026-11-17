<?php

declare(strict_types=1);

namespace Modules\Core\Scale;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** PS1: a cell is filling up, closed itself to new brokerages, or no cell has room left. */
final class CellsAlert extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(public readonly string $subject, public readonly array $lines, public readonly bool $urgent = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->urgent ? 'Urgent: ' : '').$this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Open cells', 'https://'.config('core.hq_host').'/hq/cells');
    }
}
