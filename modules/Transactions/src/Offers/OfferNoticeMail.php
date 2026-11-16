<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message to a buyer's agent about their offer: every agent with an open offer gets the same words at once. */
final class OfferNoticeMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $message, public readonly ?CarbonImmutable $deadline, public readonly ?string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Your offer on {$this->street}")->line($this->message);
        if ($this->deadline !== null) {
            $mail->line('Deadline: '.$this->deadline->format('l, F j \a\t g:i A T').'.');
        }
        if ($this->url !== null) {
            $mail->action('Review or revise your offer', $this->url);
        }

        return $mail->line('Never send earnest money on wiring instructions received by email; confirm by phone with the title company first.');
    }
}
