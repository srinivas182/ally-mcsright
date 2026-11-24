<?php

declare(strict_types=1);

namespace Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * EM3/EM4: every notification email greets the person by name ("Hello Maria Lopez,") instead of a generic "Hello,":
 * a person on file by their full name, and an address sent to with a name (Notification::route('mail', $email)->route('name', $name))
 * by that name. A notification that sets its own greeting keeps it; a bare address keeps "Hello,".
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
        if ($message instanceof MailMessage && $message->greeting === null && ($name = self::nameOf($notifiable)) !== '') {
            $message->greeting('Hello '.$name.',');
        }

        return $this->mailer->mailer($message->mailer ?? null)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }

    /** The person's name for the greeting, or '' when there is none. */
    private static function nameOf(mixed $notifiable): string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            // Notification::route('mail', $email)->route('name', $name): the name travels beside the address.
            $name = $notifiable->routes['name'] ?? '';

            return is_string($name) ? trim(preg_replace('/\s+/', ' ', strip_tags($name)) ?? '') : '';
        }
        if (! is_object($notifiable)) {
            return '';
        }
        $full = trim(trim((string) ($notifiable->first_name ?? '')).' '.trim((string) ($notifiable->last_name ?? '')));

        return $full !== '' ? $full : trim((string) ($notifiable->name ?? ''));
    }
}
