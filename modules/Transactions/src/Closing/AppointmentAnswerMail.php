<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A vendor confirmed an appointment or suggested another time. */
final class AppointmentAnswerMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $title, public readonly string $vendor, public readonly bool $confirmed,
        public readonly ?CarbonImmutable $proposed, public readonly ?string $note, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->confirmed ? 'Confirmed: ' : 'New time suggested: ')."{$this->title} at {$this->street}")
            ->line($this->confirmed ? "{$this->vendor} confirmed the {$this->title}." : "{$this->vendor} suggests ".$this->proposed?->format('l, F j \a\t g:i A').' instead. Accept it in one click on the deal.');
        if ($this->note !== null && $this->note !== '') {
            $mail->line('"'.$this->note.'"');
        }

        return $mail->action('Open the deal', url($this->path));
    }
}
