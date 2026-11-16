<?php

declare(strict_types=1);

namespace Modules\Transactions\Repairs;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Forms\Guard;
use Modules\Transactions\DealIq\FormDrafter;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Reading\PageText;
use Throwable;

/**
 * Repair requests from the inspection report (specs/ai/02, §17). Deal-IQ lists the inspector's findings (each with
 * the page and words it came from: anything it cannot point to is dropped), grouped safety → major → minor →
 * maintenance. The agent picks what to ask for, a repair or a credit (amounts are the agent's: the AI never prices
 * repairs), and Deal-IQ drafts the amendment on the brokerage's own form for signature. The buyers can see the list
 * in the deal portal once the agent shares it.
 */
final class RepairRequests
{
    public const FEATURE = 'docs.repair_read';

    public const CATEGORIES = ['safety' => 'Safety', 'major' => 'Major', 'minor' => 'Minor', 'maintenance' => 'Maintenance'];

    public const ASKS = ['repair', 'credit', 'none'];

    public function __construct(private readonly AiGateway $gateway) {}

    public function start(Deal $deal, Document $doc, User $by): string
    {
        if ($doc->deal_id !== $deal->id) {
            abort(404);
        }
        if ($deal->locked_at !== null) {
            throw ValidationException::withMessages(['document' => 'This deal is closed and locked.']);
        }
        $ulid = (string) Str::ulid();
        $id = DB::connection(Connections::cell())->table('tx_repair_requests')->insertGetId(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
            'document_id' => $doc->id, 'status' => 'reading', 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $items = $this->read($doc, $by);
        DB::connection(Connections::cell())->table('tx_repair_requests')->where('id', $id)->update(['status' => $items === null ? 'failed' : 'draft',
            'items' => json_encode($items ?? []), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.repairs.read', $deal, metadata: ['document' => $doc->filename, 'items' => count($items ?? [])], label: $deal->street);

        return $ulid;
    }

    /**
     * The agent's choices: per item, ask for a repair, a credit (with the amount) or nothing, with a note; and items
     * they add themselves.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function save(Deal $deal, string $ulid, array $items, User $by): void
    {
        $row = $this->find($deal, $ulid);
        $clean = [];
        foreach (array_slice($items, 0, 80) as $i) {
            $title = mb_substr(trim((string) ($i['title'] ?? '')), 0, 160);
            if ($title === '') {
                continue;
            }
            $note = mb_substr(trim((string) ($i['note'] ?? '')), 0, 500);
            Guard::noWiringInstructions($note, 'note');
            $ask = in_array($i['ask'] ?? null, self::ASKS, true) ? (string) $i['ask'] : 'none';
            $clean[] = ['title' => $title, 'location' => mb_substr(trim((string) ($i['location'] ?? '')), 0, 120),
                'category' => array_key_exists((string) ($i['category'] ?? ''), self::CATEGORIES) ? (string) $i['category'] : 'minor',
                'page' => isset($i['page']) ? (int) $i['page'] : null, 'quote' => mb_substr((string) ($i['quote'] ?? ''), 0, 200), 'ask' => $ask,
                'credit_cents' => $ask === 'credit' ? max(0, (int) ($i['credit_cents'] ?? 0)) : 0, 'note' => $note];
        }
        DB::connection(Connections::cell())->table('tx_repair_requests')->where('id', $row->id)->update(['items' => json_encode($clean), 'updated_at' => now()]);
    }

    /** Drafts the repair amendment for signature. */
    public function draft(Deal $deal, string $ulid, User $by): string
    {
        $row = $this->find($deal, $ulid);
        $asked = array_values(array_filter((array) json_decode((string) $row->items, true), fn (array $i): bool => in_array($i['ask'] ?? 'none', ['repair', 'credit'], true)));
        if ($asked === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one repair or credit to ask for.']);
        }
        $repairs = array_map(fn (array $i): string => $i['title'].($i['location'] !== '' ? ' ('.$i['location'].')' : '').($i['note'] !== '' ? ': '.$i['note'] : ''),
            array_values(array_filter($asked, fn (array $i): bool => $i['ask'] === 'repair')));
        $credit = array_sum(array_map(fn (array $i): int => (int) $i['credit_cents'], $asked));
        $instruction = 'Amendment for repairs after the buyer\'s inspection.'.($repairs !== [] ? ' Seller to complete, by licensed contractors where required, before closing: '.implode('; ', $repairs).'.' : '')
            .($credit > 0 ? ' Seller to give the buyer a credit of $'.number_format($credit / 100).' at closing.' : '');
        $packet = app(FormDrafter::class)->draft($deal, $instruction, $by)
            ?? throw ValidationException::withMessages(['items' => 'None of your brokerage\'s forms fits a repair amendment. Add the form under Forms.']);
        DB::connection(Connections::cell())->table('tx_repair_requests')->where('id', $row->id)->update(['status' => 'drafted', 'packet' => $packet['packet'], 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.repairs.drafted', $deal, metadata: ['repairs' => count($repairs), 'credit_cents' => $credit], label: $deal->street);

        return $packet['packet'];
    }

    public function share(Deal $deal, string $ulid, bool $on, User $by): void
    {
        $row = $this->find($deal, $ulid);
        DB::connection(Connections::cell())->table('tx_repair_requests')->where('id', $row->id)->update(['shared_at' => $on ? now() : null, 'updated_at' => now()]);
        app(AuditLogger::class)->record($on ? 'transactions.repairs.shared' : 'transactions.repairs.unshared', $deal, label: $deal->street);
    }

    public function find(Deal $deal, string $ulid): \stdClass
    {
        return DB::connection(Connections::cell())->table('tx_repair_requests')->where('deal_id', $deal->id)->where('ulid', $ulid)->first() ?? abort(404);
    }

    /**
     * @return list<array{ulid: string, document: string, status: string, items: list<array<string, mixed>>, packet: string|null, shared: bool, credit_cents: int, at: string}>
     */
    public static function forDeal(Deal $deal, bool $sharedOnly = false): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_repair_requests')->where('deal_id', $deal->id)->when($sharedOnly, fn ($q) => $q->whereNotNull('shared_at'))
            ->orderByDesc('id')->get()->map(function ($r): array {
                $items = array_values((array) json_decode((string) ($r->items ?? '[]'), true));

                return ['ulid' => (string) $r->ulid, 'document' => (string) (Document::query()->whereKey((int) $r->document_id)->value('filename') ?? ''), 'status' => (string) $r->status,
                    'items' => $items, 'packet' => $r->packet, 'shared' => $r->shared_at !== null,
                    'credit_cents' => array_sum(array_map(fn (array $i): int => ($i['ask'] ?? '') === 'credit' ? (int) ($i['credit_cents'] ?? 0) : 0, $items)),
                    'at' => CarbonImmutable::parse((string) $r->created_at)->toDateString()];
            })->all());
    }

    /**
     * @return list<array<string, mixed>>|null null when the report could not be read
     */
    private function read(Document $doc, User $by): ?array
    {
        $text = PageText::read((string) Storage::disk('local')->get($doc->path), $doc->mime);
        $pages = $text['pages'] ?? [];
        if ($pages === [] || implode('', $pages) === '') {
            return null;
        }
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'transactions.repair_read', 'repair-read:'.$doc->ulid,
                untrustedContent: array_map(fn (string $p, int $i): string => '[Page '.($i + 1)."]\n".mb_substr($p, 0, 6000), array_slice($pages, 0, 60), array_keys(array_slice($pages, 0, 60))),
                outputSchema: ['type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array']]], userId: $by->id));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $order = array_flip(array_keys(self::CATEGORIES));
        $items = [];
        foreach (array_slice((array) ($r->json['items'] ?? []), 0, 60) as $i) {
            $i = (array) $i;
            $page = (int) ($i['page'] ?? 0);
            $quote = trim((string) ($i['quote'] ?? ''));
            $title = mb_substr(trim((string) ($i['title'] ?? '')), 0, 160);
            if ($title === '' || ! DocIq::onPage($quote, $pages[$page - 1] ?? '')) {
                continue; // nothing Deal-IQ cannot point to in the report
            }
            $category = array_key_exists((string) ($i['category'] ?? ''), self::CATEGORIES) ? (string) $i['category'] : 'minor';
            $suggest = in_array($i['suggest'] ?? null, self::ASKS, true) ? (string) $i['suggest'] : 'none';
            $items[] = ['title' => $title, 'location' => mb_substr(trim((string) ($i['location'] ?? '')), 0, 120), 'category' => $category, 'page' => $page,
                'quote' => mb_substr($quote, 0, 200), 'ask' => $category === 'maintenance' ? 'none' : $suggest, 'credit_cents' => 0, 'note' => ''];
        }
        usort($items, fn (array $a, array $b): int => $order[$a['category']] <=> $order[$b['category']]);

        return $items;
    }
}
