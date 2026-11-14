<?php

declare(strict_types=1);

namespace Modules\Sites\Ai;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your market updates are ready to review" (specs/websites/10). */
final class DraftsReady extends Notification
{
    use Queueable;

    /**
     * @param  list<array{title: string, ulid: string}>  $posts
     */
    public function __construct(public readonly array $posts) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(count($this->posts) === 1 ? 'Your market update is ready to review' : count($this->posts).' market updates are ready to review')
            ->line('This month\'s figures are in. We drafted these posts from your MLS data; review and publish when you are happy:');
        foreach ($this->posts as $p) {
            $mail->line('• '.$p['title']);
        }

        return $mail->action('Review drafts', url('/settings/website/blog'))->line('Nothing is published until you choose to.');
    }
}
