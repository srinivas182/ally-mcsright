<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BankDetailsChanged extends Notification
{
    use Queueable;

    public function __construct(public readonly string $last4, public readonly ?string $previousLast4, public readonly CarbonImmutable $usableFrom, public readonly ?string $changedBy) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your payout bank account was changed')
            ->line('Your commission will now be paid to the account ending '.$this->last4.($this->previousLast4 !== null ? " (previously ending {$this->previousLast4})" : '').'.')
            ->line($this->changedBy !== null ? "The change was made by {$this->changedBy} at your brokerage." : 'The change was made from your account.')
            ->line('To protect you, no payouts go to the new account until '.$this->usableFrom->toDayDateTimeString().'.')
            ->line('If you did not make this change, contact your brokerage immediately — this is how commission theft usually starts.');
    }
}
