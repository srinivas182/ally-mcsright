<?php

declare(strict_types=1);

namespace Modules\Transactions\Review;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Forms\Guard;
use Modules\Transactions\Compliance\Checks;
use Modules\Transactions\Compliance\Findings;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Throwable;

/**
 * The broker's review copilot for a submitted file (specs/ai/02, DQ6): a brief written from the file's own facts
 * (what is there, what was waived or dismissed and why, what deserves a second look), the findings ranked with how
 * often each check has been right, a suggested note for sending it back, and one-click approve or send back. The
 * broker decides; the AI never approves anything.
 */
final class FileReview
{
    public const FEATURE = 'docs.review_brief';

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array<string, mixed>
     */
    public static function facts(Deal $deal): array
    {
        $db = DB::connection(Connections::cell());
        $findings = Findings::forDeal($deal);
        $items = Item::query()->where('deal_id', $deal->id)->get();

        return ['address' => $deal->street.', '.$deal->city.', '.$deal->state_code, 'type' => $deal->type, 'status' => $deal->status,
            'price' => '$'.number_format(intdiv($deal->price_cents, 100)), 'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString(),
            'agent' => User::query()->whereKey($deal->agent_user_id)->first()->name ?? '', 'compliance_score' => $findings['score'],
            'documents' => $db->table('tx_documents')->where('deal_id', $deal->id)->whereNull('split_into')->count(),
            'checklist' => ['approved' => $items->where('status', 'approved')->count(), 'waived' => $items->where('status', 'waived')->map(fn (Item $i): string => $i->label)->values()->all(),
                'still_needed' => $items->where('required', true)->whereIn('status', ['missing', 'submitted', 'needs_fix'])->map(fn (Item $i): string => $i->label.' ('.$i->status.')')->values()->all()],
            'open_findings' => array_map(fn (array $f): string => $f['severity'].': '.$f['message'], $findings['open']),
            'dismissed_findings' => array_map(fn (array $f): string => $f['message'].' (reason: '.($f['dismiss_reason'] ?? '').')', $findings['dismissed'])];
    }

    /**
     * @return array{summary: string, note: string, ai: bool}
     */
    public function brief(Deal $deal, User $by): array
    {
        $facts = self::facts($deal);
        $plain = self::plain($facts);
        $out = $plain + ['ai' => false];
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'docs.review_brief', 'review-brief:'.$deal->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: ['facts' => (string) json_encode($facts)], untrustedContent: [], outputSchema: ['type' => 'object', 'required' => ['summary', 'note'],
                    'properties' => ['summary' => ['type' => 'string'], 'note' => ['type' => 'string']]], userId: $by->id));
            $summary = trim((string) ($r->json['summary'] ?? ''));
            $note = trim((string) ($r->json['note'] ?? ''));
            $allowed = self::amounts((string) json_encode($facts));
            if ($summary !== '' && $note !== '' && array_diff(self::amounts($summary.' '.$note), $allowed) === []) {
                $out = ['summary' => mb_substr($summary, 0, 2000), 'note' => mb_substr($note, 0, 2000), 'ai' => true];
            }
        } catch (Throwable $e) {
            report($e);
        }
        DB::connection(Connections::cell())->table('tx_review_briefs')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'summary' => $out['summary'], 'note' => $out['note'],
            'ai' => $out['ai'], 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return $out;
    }

    /**
     * The page: the brief, the findings ranked, and how often each check has been right.
     *
     * @return array<string, mixed>
     */
    public static function page(Deal $deal): array
    {
        $brief = DB::connection(Connections::cell())->table('tx_review_briefs')->where('deal_id', $deal->id)->orderByDesc('id')->first();
        $accuracy = Findings::accuracy();
        $findings = Findings::forDeal($deal);
        $rank = fn (array $f): array => $f + ['check' => Checks::CATALOG[$f['code']][0] ?? $f['code'], 'accuracy' => $accuracy[$f['code']]['accuracy'] ?? null];
        $row = DB::connection(Connections::cell())->table('tx_deals')->where('id', $deal->id)->first(['file_submitted_at', 'file_approved_at', 'file_returned_at', 'file_return_note']);

        return ['deal' => ['ulid' => $deal->ulid, 'street' => $deal->street, 'status' => $deal->status, 'agent' => User::query()->whereKey($deal->agent_user_id)->first()->name ?? ''],
            'facts' => self::facts($deal), 'score' => $findings['score'],
            'open' => array_map($rank, $findings['open']), 'dismissed' => array_map($rank, $findings['dismissed']),
            'brief' => $brief !== null ? ['summary' => (string) $brief->summary, 'note' => (string) $brief->note, 'ai' => (bool) $brief->ai] : null,
            'file' => ['submitted_at' => $row?->file_submitted_at, 'approved_at' => $row?->file_approved_at, 'returned_at' => $row?->file_returned_at, 'return_note' => $row?->file_return_note]];
    }

    public function approve(Deal $deal, User $by): void
    {
        $row = DB::connection(Connections::cell())->table('tx_deals')->where('id', $deal->id)->first(['file_submitted_at']);
        if ($row?->file_submitted_at === null) {
            throw ValidationException::withMessages(['file' => 'The agent has not sent this file for review.']);
        }
        DB::connection(Connections::cell())->table('tx_deals')->where('id', $deal->id)->update(['file_approved_at' => now(), 'file_approved_by' => $by->id, 'file_return_note' => null]);
        app(AuditLogger::class)->record('transactions.file.approved', $deal, severity: 'notice', label: $deal->street);
        User::query()->whereKey($deal->agent_user_id)->first()?->notify(new FileReviewMail($deal->street, true, null, '/deals/'.$deal->ulid));
    }

    public function sendBack(Deal $deal, string $note, User $by): void
    {
        $note = trim($note);
        Guard::noWiringInstructions($note, 'note');
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Tell the agent what to fix.']);
        }
        DB::connection(Connections::cell())->table('tx_deals')->where('id', $deal->id)->update(['file_submitted_at' => null, 'file_approved_at' => null, 'file_returned_at' => now(),
            'file_return_note' => mb_substr($note, 0, 2000)]);
        app(AuditLogger::class)->record('transactions.file.returned', $deal, metadata: ['note' => mb_substr($note, 0, 300)], severity: 'notice', label: $deal->street);
        User::query()->whereKey($deal->agent_user_id)->first()?->notify(new FileReviewMail($deal->street, false, $note, '/deals/'.$deal->ulid));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{summary: string, note: string}
     */
    private static function plain(array $f): array
    {
        $waived = (array) $f['checklist']['waived'];
        $needed = (array) $f['checklist']['still_needed'];
        $summary = ucfirst((string) $f['type']).' deal at '.$f['address'].' ('.str_replace('_', ' ', (string) $f['status']).'), '.$f['price'].', agent '.$f['agent'].'. '
            .$f['documents'].' document(s); '.$f['checklist']['approved'].' checklist item(s) approved'.($waived !== [] ? ', waived: '.implode(', ', $waived) : '').'. '
            .(count((array) $f['dismissed_findings']) > 0 ? count((array) $f['dismissed_findings']).' finding(s) dismissed by the agent with reasons; check them. ' : '')
            .($needed !== [] ? 'Still needed: '.implode(', ', $needed).'.' : 'Nothing required is outstanding.');
        $note = $needed !== [] ? 'Please complete: '.implode(', ', $needed).'.' : 'The file looks complete.';

        return ['summary' => $summary, 'note' => $note];
    }

    /**
     * @return list<string>
     */
    private static function amounts(string $text): array
    {
        preg_match_all('/\$\s?(\d[\d,]*)/u', $text, $m);

        return array_values(array_unique(array_map(fn (string $n): string => str_replace(',', '', $n), $m[1])));
    }
}
