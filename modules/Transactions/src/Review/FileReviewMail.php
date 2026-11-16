<?php

declare(strict_types=1);

namespace Modules\Transactions\Review;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The broker approved the file, or sent it back with what to fix. */
final class FileReviewMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly bool $approved, public readonly ?string $note, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->approved ? 'File approved: ' : 'File sent back: ').$this->street)
            ->line($this->approved ? "Your broker approved the file for {$this->street}." : "Your broker sent the file for {$this->street} back:");
        if ($this->note !== null) {
            $mail->line($this->note);
        }

        return $mail->action('Open the deal', url($this->path));
    }
}
