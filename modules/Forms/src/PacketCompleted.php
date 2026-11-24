<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PacketCompleted extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $brokerage, public readonly string $pdf, public readonly bool $deal = true) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Signed: {$this->title}")
            ->line("Everyone has signed **{$this->title}**. Your signed copy is attached, with its signature certificate. Please keep it for your records.");
        // EM4: the wire fraud warning belongs on home sale paperwork, not on a brokerage's own agreements.
        if ($this->deal) {
            $mail->line('Wire fraud warning: never wire money based on an email or text. Call a number you already know first.');
        }

        return $mail->attachData($this->pdf, 'Signed - '.$this->title.'.pdf', ['mime' => 'application/pdf']);
    }
}
