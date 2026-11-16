<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The deal's dates were recalculated: what moved, and who should be told (specs/ai/02, §8). */
final class DatesChangedMail extends Notification
{
    use Queueable;

    /**
     * @param  list<array{label: string, from: string|null, to: string|null}>  $changes
     * @param  list<string>  $tell
     */
    public function __construct(public readonly string $street, public readonly array $changes, public readonly array $tell, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Dates changed on {$this->street}");
        foreach ($this->changes as $c) {
            $mail->line('• '.$c['label'].': '.($c['from'] ?? 'new').' → '.($c['to'] ?? 'removed'));
        }
        if ($this->tell !== []) {
            $mail->line('Tell: '.implode(', ', $this->tell).'.');
        }

        return $mail->action('Review the dates', url($this->path));
    }
}
