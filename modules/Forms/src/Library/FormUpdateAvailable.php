<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the people who manage forms that an association released new editions of forms the brokerage uses. */
final class FormUpdateAvailable extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $forms  "Residential Listing Agreement: 11-2025 → 02-2026"
     */
    public function __construct(public readonly string $library, public readonly array $forms) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("New form editions from {$this->library}")->line("{$this->library} released new editions of forms you use:");
        foreach ($this->forms as $f) {
            $mail->line('• '.$f);
        }

        return $mail->line('See what changed, then update with one click. Packets already sent keep the edition they were sent with.')
            ->action('Review the updates', url('/forms/libraries'));
    }
}
