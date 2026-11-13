<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a signer who already signed that terms changed, with a link to initial only the changes. */
final class ChangesToInitial extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $changes  "Label: old → new"
     */
    public function __construct(public readonly string $title, public readonly string $brokerage, public readonly string $agent, public readonly array $changes,
        public readonly string $reason, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Please initial changes: {$this->title}")
            ->line("{$this->agent} at {$this->brokerage} changed documents you already signed ({$this->title}). Reason: {$this->reason}");
        foreach ($this->changes as $c) {
            $mail->line('• '.$c);
        }

        return $mail->line('You only need to initial these changes; your signature stays on everything else.')
            ->action('Review and initial', $this->url)
            ->line('Wire fraud warning: never wire money based on an email or text. Payment instructions never change by email — call your agent or the title company on a number you already know before sending any money.');
    }
}
