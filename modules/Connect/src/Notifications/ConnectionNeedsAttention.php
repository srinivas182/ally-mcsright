<?php

declare(strict_types=1);

namespace Modules\Connect\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A connected app stopped working (specs/connect/01). */
final class ConnectionNeedsAttention extends Notification
{
    use Queueable;

    public function __construct(public readonly string $app, public readonly ?string $label, public readonly string $error, public readonly bool $reconnect,
        public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->reconnect ? $this->app.' needs to be connected again' : $this->app.' has stopped syncing')
            ->line($this->reconnect
                ? 'The sign-in for '.$this->app.($this->label !== null ? ' ('.$this->label.')' : '').' no longer works, so nothing is syncing. Connect it again to carry on.'
                : $this->app.($this->label !== null ? ' ('.$this->label.')' : '').' failed several times in a row.')
            ->line('What happened: '.$this->error)
            ->action($this->reconnect ? 'Connect again' : 'See the connection', $this->url);
    }
}
