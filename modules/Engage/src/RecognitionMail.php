<?php

declare(strict_types=1);

namespace Modules\Engage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A recognition message from the broker (EN1, E21). An AI-voiced message says so. */
final class RecognitionMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $from, public readonly string $occasion, public readonly string $text, public readonly string $path,
        public readonly string $audio) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject("A message from {$this->from}: {$this->occasion}")->line('"'.$this->text.'"');
        if ($this->audio !== 'none') {
            $m->action('Listen to the message', url($this->path));
        }
        if ($this->audio === 'ai_voice') {
            $m->line("The audio is spoken by an AI copy of {$this->from}'s voice, made with their consent. The words are theirs.");
        }

        return $m;
    }
}
