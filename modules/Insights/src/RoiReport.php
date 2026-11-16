<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Crm\Leads\LeadReports;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Lead;
use Modules\Transactions\Models\Deal;

/**
 * The monthly ROI report (specs/insights/01, K3): what RightAlly did for the brokerage last month, in hours saved and
 * business won, emailed to owners on the 1st. Time saved uses stated, conservative minutes per task, shown on the page.
 */
final class RoiReport
{
    /** Minutes a person would have spent on each, done by hand. */
    public const MINUTES = ['automation_step' => 4, 'ai_reply' => 3, 'document_read' => 6];

    /**
     * @return array<string, mixed>
     */
    public function month(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $db = DB::connection(Connections::cell());
        $steps = $db->table('workflow_run_steps')->where('status', 'done')->whereBetween('at', [$from, $to])->count();
        $runs = $db->table('workflow_runs')->where('status', 'done')->whereBetween('finished_at', [$from, $to])->count();
        $aiReplies = (int) AiThread::query()->whereBetween('created_at', [$from, $to])->sum('messages_sent');
        $docs = $db->table('tx_document_readings')->whereBetween('created_at', [$from, $to])->count();
        $leads = Lead::query()->where('status', '!=', 'needs_review')->whereBetween('created_at', [$from, $to]);
        $answered = (clone $leads)->whereNotNull('response_seconds')->pluck('response_seconds')->sort()->values();
        $target = app(LeadReports::class)->targets()['target_minutes'] * 60;
        $closed = Deal::query()->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()]);
        $returns = app(LeadReports::class)->returns($from, $to);
        $website = collect($returns['sources'])->firstWhere('key', 'website');
        $minutes = $steps * self::MINUTES['automation_step'] + $aiReplies * self::MINUTES['ai_reply'] + $docs * self::MINUTES['document_read'];

        return [
            'month' => $from->format('Y-m'),
            'label' => $from->format('F Y'),
            'hours_saved' => round($minutes / 60, 1),
            'automations' => ['runs' => $runs, 'steps' => $steps],
            'ai_replies' => $aiReplies,
            'documents_read' => $docs,
            'leads' => ['count' => (clone $leads)->count(), 'median_minutes' => $answered->isEmpty() ? null : (int) round($answered[intdiv($answered->count(), 2)] / 60),
                'within_target' => $answered->isEmpty() ? null : (int) round(100 * $answered->filter(fn ($s): bool => (int) $s <= $target)->count() / max(1, (clone $leads)->count()))],
            'closings' => ['sides' => (clone $closed)->count(), 'gci_cents' => (int) (clone $closed)->sum('gci_cents')],
            'website' => $website === null ? null : ['leads' => $website['leads'], 'closed' => $website['closed'], 'company_dollar_cents' => $website['company_dollar_cents']],
            'minutes' => self::MINUTES,
        ];
    }

    /** On the 1st: last month's report to every owner. */
    public function sendDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if ($today->day !== 1) {
            return 0;
        }
        $report = $this->month($today->subMonth());
        $owners = User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get();
        foreach ($owners as $owner) {
            $owner->notify(new RoiMail($report));
        }

        return $owners->count();
    }
}
