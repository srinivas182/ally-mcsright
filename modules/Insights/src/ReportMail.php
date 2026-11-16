<?php

declare(strict_types=1);

namespace Modules\Insights;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A scheduled report, with the CSV attached (specs/insights/01, K2). */
final class ReportMail extends Notification
{
    use Queueable;

    /**
     * @param  array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, total: int}  $result
     */
    public function __construct(public readonly string $name, public readonly array $result, public readonly string $ulid) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Report: '.$this->name)
            ->line($this->result['total'].' '.($this->result['total'] === 1 ? 'row' : 'rows').'. The spreadsheet is attached.')
            ->action('Open the report', url('/insights/reports?report='.$this->ulid))
            ->attachData(SavedReports::csv($this->result), str($this->name)->slug().'.csv', ['mime' => 'text/csv']);
    }
}
