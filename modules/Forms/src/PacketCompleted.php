<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PacketCompleted extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $brokerage, public readonly string $pdf) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Signed: {$this->title}")
            ->line("Everyone has signed {$this->title}. Your copy is attached, with its signature certificate.")
            ->line('Wire fraud warning: never wire money based on an email or text — call a number you already know first.')
            ->attachData($this->pdf, 'Signed - '.$this->title.'.pdf', ['mime' => 'application/pdf']);
    }
}
