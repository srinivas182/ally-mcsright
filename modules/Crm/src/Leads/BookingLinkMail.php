<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** After a Lead-IQ call: the link to pick a time with an agent, for people we may not text. */
final class BookingLinkMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brand, public readonly string $name, public readonly string $url, public readonly string $lang = 'en') {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $es = $this->lang === 'es';

        return (new MailMessage)->subject($es ? "{$this->brand}: escoja una hora" : "{$this->brand}: pick a time to talk")
            ->greeting(($es ? 'Hola ' : 'Hello ').($this->name !== '' ? $this->name : ($es ? '' : 'there')).',')
            ->line($es ? 'Gracias por su interés. Escoja la hora que mejor le quede para hablar con un agente.' : 'Thanks for your interest. Pick the time that suits you to talk with an agent.')
            ->action($es ? 'Escoger una hora' : 'Pick a time', $this->url)
            ->line($es ? 'El enlace funciona durante 7 días.' : 'The link works for 7 days.');
    }
}
