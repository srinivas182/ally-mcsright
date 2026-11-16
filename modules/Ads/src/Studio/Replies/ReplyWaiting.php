<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Replies;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A comment or message waits for the agent (specs/grow/13, SA5c). No message text in the email. */
final class ReplyWaiting extends Notification
{
    use Queueable;

    public function __construct(public readonly string $channel, public readonly string $kind, public readonly bool $needsYou) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->channel === 'instagram' ? 'Instagram' : 'Facebook';

        return (new MailMessage)->subject('New '.$this->kind.' on '.$where.($this->needsYou ? ' that needs you' : ' with a reply ready'))
            ->line($this->needsYou ? 'Someone wrote something Ally Studio leaves to you to answer.' : 'Ally Studio has drafted a reply. Check it and send it with one tap.')
            ->line($this->kind === 'message' ? 'Facebook and Instagram allow a reply within 24 hours.' : 'A quick reply keeps the post near the top of people\'s feeds.')
            ->action('Open Replies', url('/studio/replies'));
    }
}
