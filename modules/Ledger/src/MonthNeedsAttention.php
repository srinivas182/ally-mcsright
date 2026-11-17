<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The nightly month-end check found something that must be fixed before the month can close (FH1). */
final class MonthNeedsAttention extends Notification
{
    use Queueable;

    /** @param  list<string>  $titles */
    public function __construct(public readonly string $month, public readonly array $titles) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Your books for {$this->month} need attention")
            ->line("The nightly check of your books for {$this->month} found something to fix before the month can be closed:");
        foreach (array_slice($this->titles, 0, 8) as $t) {
            $mail->line('• '.$t);
        }

        return $mail->action('See what to fix', url('/finance/periods'));
    }
}
