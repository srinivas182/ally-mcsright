<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client answered the post-closing check-in. */
final class PortalFeedbackMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $street, public readonly int $rating, public readonly ?string $comment,
        public readonly bool $mayQuote, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->who} rated {$this->street} {$this->rating}/5")->line("{$this->who} rated their experience {$this->rating} out of 5.");
        if ($this->comment !== null && $this->comment !== '') {
            $mail->line('"'.$this->comment.'"');
        }
        $mail->line($this->mayQuote ? 'They are happy for you to share their words as a review.' : 'Keep this private: they did not agree to it being shared.');

        return $mail->action('Open the deal', url($this->path));
    }
}
