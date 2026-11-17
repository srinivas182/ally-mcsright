<?php

declare(strict_types=1);

namespace Modules\Insights;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monthly (DH1): database health, the 90-day commission forecast and what the database is worth, in plain words. */
final class DatabaseReportMail extends Notification
{
    use Queueable;

    /** @param  array{health: array{score: int, total: int, checks: list<array{key: string, label: string, count: int, fix: string, href: string}>, to_fix: int}, forecast: array{buckets: list<array{days: int, low_cents: int, high_cents: int}>, reasons: list<string>, average_gci_cents: int}, value: array{people: int, deals_a_year: float, value_cents: int, rate: float, from_history: bool, basis: string}, proof: list<array{group: string, deals: int, gci_cents: int}>}  $report */
    public function __construct(public readonly array $report, public readonly bool $brokerage) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $h = $this->report['health'];
        $f = $this->report['forecast']['buckets'][2];
        $v = $this->report['value'];
        $top = collect($h['checks'])->sortByDesc('count')->first();
        $mail = (new MailMessage)->subject(($this->brokerage ? 'Your brokerage database' : 'Your database').": {$h['score']}% healthy")
            ->line(($this->brokerage ? 'The brokerage database is ' : 'Your database is ')."{$h['score']}% healthy ({$h['total']} people).")
            ->line('Commission likely in the next 90 days: '.DatabaseValue::usd($f['low_cents']).' to '.DatabaseValue::usd($f['high_cents']).'.')
            ->line("Past clients and sphere: {$v['people']} people, worth about ".DatabaseValue::usd($v['value_cents']).' a year in repeat and referral business. '.$v['basis']);
        if ($top !== null && $top['count'] > 0) {
            $mail->line("First thing to fix: {$top['label']} ({$top['count']}). {$top['fix']}.");
        }

        return $mail->action('Open the database report', url('/insights/database'));
    }
}
