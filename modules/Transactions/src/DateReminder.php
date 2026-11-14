<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Reminds the agent of a deal's key dates coming up or missed. */
final class DateReminder extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines  "Inspection period ends — Fri 12 Jun (in 2 days)"
     */
    public function __construct(public readonly string $street, public readonly array $lines, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Key dates: {$this->street}")->line("Coming up or missed on {$this->street}:");
        foreach ($this->lines as $line) {
            $mail->line('• '.$line);
        }

        return $mail->action('Open the deal', $this->url)->line('Tick a date off on the deal once it is done, and you will not be reminded again.');
    }
}
