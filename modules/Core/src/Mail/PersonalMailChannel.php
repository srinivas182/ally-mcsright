<?php

declare(strict_types=1);

namespace Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * EM3: every notification email greets the person by their first name ("Hi Maria,") instead of a generic "Hello,".
 * A notification that sets its own greeting keeps it; one sent to a bare address (no person on file) keeps "Hello,".
 * Same steps as Laravel's MailChannel::send, with the greeting added between building and sending.
 */
final class PersonalMailChannel extends MailChannel
{
    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification)
    {
        if (! method_exists($notification, 'toMail')) {
            return null;
        }
        $message = $notification->toMail($notifiable);

        if (! $notifiable->routeNotificationFor('mail', $notification) && ! $message instanceof Mailable) {
            return null;
        }
        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }
        if ($message instanceof MailMessage && $message->greeting === null) {
            $first = trim((string) ($notifiable->first_name ?? ''));
            if ($first !== '') {
                $message->greeting('Hi '.$first.',');
            }
        }

        return $this->mailer->mailer($message->mailer ?? null)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }
}
