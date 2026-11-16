<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To a vendor: a new appointment (confirm or suggest another time), a reminder, a new time, or a cancellation. */
final class VendorMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $title, public readonly CarbonImmutable $starts, public readonly string $agent,
        public readonly string $agentEmail, public readonly ?string $url, public readonly bool $cancelled, public readonly bool $reminder = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->starts->format('l, F j \a\t g:i A T');
        $mail = (new MailMessage)->replyTo($this->agentEmail);
        if ($this->cancelled) {
            return $mail->subject("Cancelled: {$this->title} at {$this->street}")->line("{$this->agent} has cancelled the {$this->title} at {$this->street} on {$when}.");
        }
        $mail->subject(($this->reminder ? 'Tomorrow: ' : '')."{$this->title} at {$this->street}, {$when}")
            ->line(($this->reminder ? 'A reminder: ' : '')."{$this->agent} has the {$this->title} at {$this->street} on {$when}.");

        return $this->url !== null ? $mail->action('Confirm or suggest another time', $this->url) : $mail->line('Reply to this email if anything changes.');
    }
}
