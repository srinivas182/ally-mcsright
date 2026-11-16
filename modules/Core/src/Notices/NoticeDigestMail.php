<?php

declare(strict_types=1);

namespace Modules\Core\Notices;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The alerts held for quiet hours or the daily digest, in one email (specs/core/10, N1). */
final class NoticeDigestMail extends Notification
{
    use Queueable;

    /**
     * @param  list<array{title: string, url: string|null}>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $n = count($this->items);
        $mail = (new MailMessage)->subject($n === 1 ? '1 update while you were away' : "{$n} updates while you were away");
        foreach (array_slice($this->items, 0, 25) as $item) {
            $mail->line('• '.$item['title']);
        }
        if ($n > 25) {
            $mail->line('…and '.($n - 25).' more.');
        }

        return $mail->action('Open your notifications', url('/notifications'));
    }
}
