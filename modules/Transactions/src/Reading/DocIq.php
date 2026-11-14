<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

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
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Pdf;
use Modules\Forms\Summaries;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reviews;
use setasign\Fpdi\Fpdi;

/**
 * DocIQ reading (specs/ai/03, Sprint 15A). Every uploaded PDF or photo is read once:
 *  - text per page (direct, or OCR on our own servers for scans and photos);
 *  - recognised and, when one upload holds several documents, split and filed into the right checklist slots;
 *  - facts extracted by AI from the redacted text, each kept only if its quote is really on its page;
 *  - documents the file now needs (an HOA, a pre-1978 home…) added to the checklist with the page that says so;
 *  - sensitive numbers found, for a warning and a safe copy with them blacked out.
 * "Ask this document" answers only from its pages, with quotes checked the same way.
 */
final class DocIq
{
    public const READ = 'docs.read';

    public const ASK = 'docs.ask';

    public const FACTS = ['buyer_names', 'seller_names', 'property_address', 'price', 'earnest_money', 'contract_date', 'closing_date', 'option_period_end',
        'inspection_deadline', 'financing_deadline', 'appraisal_deadline', 'year_built', 'title_company', 'lender',
        'commission_total', 'commission_listing', 'commission_buyer', 'earnest_money_credit'];

    public const FACT_LABELS = ['buyer_names' => 'Buyers', 'seller_names' => 'Sellers', 'property_address' => 'Property', 'price' => 'Price',
        'earnest_money' => 'Earnest money', 'contract_date' => 'Contract date', 'closing_date' => 'Closing date', 'option_period_end' => 'Option period ends',
        'inspection_deadline' => 'Inspection deadline', 'financing_deadline' => 'Financing deadline', 'appraisal_deadline' => 'Appraisal deadline',
        'year_built' => 'Year built', 'title_company' => 'Title company', 'lender' => 'Lender',
        'commission_total' => 'Total commission', 'commission_listing' => 'Listing-side commission', 'commission_buyer' => "Buyer's-side commission",
        'earnest_money_credit' => 'Earnest money credited'];

    public const DATE_FACTS = ['contract_date', 'closing_date', 'option_period_end', 'inspection_deadline', 'financing_deadline', 'appraisal_deadline'];

    private const FACT_SCHEMA = ['type' => 'object', 'required' => ['facts'], 'properties' => ['facts' => ['type' => 'array', 'items' => ['type' => 'object',
        'required' => ['name', 'value', 'page', 'quote'], 'properties' => ['name' => ['type' => 'string'], 'value' => ['type' => 'string'], 'page' => ['type' => 'integer'],
            'quote' => ['type' => 'string']]]]]];

    private const ASK_SCHEMA = ['type' => 'object', 'required' => ['found', 'answer', 'citations'], 'properties' => ['found' => ['type' => 'boolean'], 'answer' => ['type' => 'string'],
        'citations' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['page', 'quote'], 'properties' => ['page' => ['type' => 'integer'], 'quote' => ['type' => 'string']]]]]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants) {}

    public static function readable(string $mime): bool
    {
        return in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /** Reads one document (run by the ReadDocument job right after upload). */
    public function read(Document $doc): DocumentReading
    {
        $reading = DocumentReading::query()->firstOrCreate(['document_id' => $doc->id], ['status' => 'queued']);
        $text = PageText::read((string) Storage::disk('local')->get($doc->path), $doc->mime);
        if ($text === null) {
            $reading->update(['status' => 'unsupported', 'read_at' => now()]);

            return $reading;
        }
        $pages = $text['pages'];
        $segments = DocTypes::segment($pages);
        $first = $segments[0]['type'] ?? null;
        $reading->update(['status' => 'read', 'pages' => count($pages), 'page_text' => $pages, 'methods' => $text['methods'], 'doc_type' => $first,
            'title' => DocTypes::label($first), 'segments' => array_map(fn (array $s): array => $s + ['title' => DocTypes::label($s['type'])], $segments),
            'sensitive' => Sensitive::scan($pages), 'facts' => $this->facts($doc, $pages), 'read_at' => now(), 'error' => null]);

        $deal = Deal::query()->findOrFail($doc->deal_id);
        if ($doc->via !== 'esign' && $doc->via !== 'split') {
            $this->file($deal, $doc, $reading);
        }
        $this->requirements($deal, $doc, $pages);
        app(ComplianceScan::class)->run($deal);

        return $reading;
    }

    /**
     * Facts from the AI, kept only when the quote is on the cited page and states the value.
     *
     * @param  list<string>  $pages
     * @return list<array{name: string, value: string, page: int, quote: string}>
     */
    private function facts(Document $doc, array $pages): array
    {
        if (implode('', $pages) === '') {
            return [];
        }
        try {
            $result = $this->gateway->run(new AiTask(self::READ, 'docs.read_document', 'docs-read:'.$doc->ulid,
                untrustedContent: self::pagesForAi($pages), outputSchema: self::FACT_SCHEMA, userId: $doc->getAttribute('uploaded_by')));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
        $out = [];
        foreach ((array) ($result->json['facts'] ?? []) as $f) {
            $name = (string) ($f['name'] ?? '');
            $value = trim((string) ($f['value'] ?? ''));
            $page = (int) ($f['page'] ?? 0);
            $quote = trim((string) ($f['quote'] ?? ''));
            if (! in_array($name, self::FACTS, true) || $value === '' || isset($out[$name]) || ! self::onPage($quote, $pages[$page - 1] ?? '') || ! self::states($quote, $value)) {
                continue;
            }
            $out[$name] = ['name' => $name, 'value' => mb_substr($value, 0, 200), 'page' => $page, 'quote' => mb_substr($quote, 0, 200)];
        }

        return array_values($out);
    }

    /** Files the document (or each document inside it) into the checklist. */
    private function file(Deal $deal, Document $doc, DocumentReading $reading): void
    {
        $segments = (array) $reading->segments;
        $items = Item::query()->where('deal_id', $deal->id)->orderBy('sort')->get();
        if (count($segments) <= 1) {
            if ($doc->item_id === null && ($item = DocTypes::matchItem($reading->doc_type, $items)) instanceof Item) {
                $this->attach($doc, $item);
            }

            return;
        }
        if ($doc->mime !== 'application/pdf') {
            return;
        }
        $source = (string) Storage::disk('local')->get($doc->path);
        $pages = (array) $reading->page_text;
        foreach ($segments as $s) {
            $item = $doc->item_id !== null && DocTypes::matchItem($s['type'], Item::query()->whereKey($doc->item_id)->get()) !== null
                ? Item::query()->find($doc->item_id) : DocTypes::matchItem($s['type'], $items);
            $path = "tx/{$deal->tenant_id}/{$deal->ulid}/".Str::ulid().'.pdf';
            Storage::disk('local')->put($path, self::pages($source, (int) $s['from'], (int) $s['to']));
            $child = Document::query()->create(['deal_id' => $deal->id, 'item_id' => null, 'filename' => mb_substr(DocTypes::label($s['type']).' (pages '.$s['from'].'–'.$s['to'].' of '.$doc->filename.')', 0, 200),
                'path' => $path, 'mime' => 'application/pdf', 'size' => (int) Storage::disk('local')->size($path), 'sha256' => hash('sha256', (string) Storage::disk('local')->get($path)),
                'version' => 1, 'via' => 'split', 'uploaded_by' => $doc->getAttribute('uploaded_by')]);
            $child->forceFill(['parent_document_id' => $doc->id, 'pages_from' => $s['from'], 'pages_to' => $s['to']])->save();
            $slice = array_slice($pages, (int) $s['from'] - 1, (int) $s['to'] - (int) $s['from'] + 1);
            DocumentReading::query()->create(['document_id' => $child->id, 'status' => 'read', 'pages' => count($slice), 'page_text' => $slice,
                'methods' => array_slice((array) $reading->methods, (int) $s['from'] - 1, count($slice)), 'doc_type' => $s['type'], 'title' => DocTypes::label($s['type']),
                'segments' => [['type' => $s['type'], 'title' => DocTypes::label($s['type']), 'from' => 1, 'to' => count($slice)]], 'sensitive' => Sensitive::scan($slice),
                'facts' => array_values(array_map(fn (array $f): array => ['page' => $f['page'] - (int) $s['from'] + 1] + $f, array_filter((array) $reading->facts,
                    fn (array $f): bool => $f['page'] >= $s['from'] && $f['page'] <= $s['to']))), 'read_at' => now()]);
            if ($item instanceof Item) {
                $this->attach($child, $item);
            }
        }
        $doc->forceFill(['split_into' => count($segments), 'item_id' => null])->save();
        app(AuditLogger::class)->record('transactions.document.split', $deal, metadata: ['filename' => $doc->filename, 'documents' => count($segments)], label: $deal->street);
    }

    private function attach(Document $doc, Item $item): void
    {
        $doc->forceFill(['item_id' => $item->id, 'version' => Document::query()->where('item_id', $item->id)->count() + 1])->save();
        if (in_array($item->status, ['missing', 'needs_fix'], true)) {
            $item->update(['status' => 'submitted', 'submitted_at' => now(), 'fix_reason' => null]);
            if ($item->getAttribute('assigned_reviewer_id') === null) {
                app(Reviews::class)->assign($item);
            }
        }
    }

    /**
     * @param  list<string>  $pages
     */
    private function requirements(Deal $deal, Document $doc, array $pages): void
    {
        $covered = array_merge(Item::query()->where('deal_id', $deal->id)->pluck('label')->all(),
            DocumentReading::query()->whereIn('document_id', Document::query()->where('deal_id', $deal->id)->select('id'))->pluck('doc_type')->filter()
                ->map(fn ($t): string => DocTypes::label((string) $t))->all());
        $sort = (int) Item::query()->where('deal_id', $deal->id)->max('sort');
        foreach (Requirements::find($pages, array_values(array_map('strval', $covered))) as $r) {
            $item = Item::query()->create(['deal_id' => $deal->id, 'label' => $r['label'], 'required' => true, 'stage' => 'contract', 'sort' => ++$sort, 'status' => 'missing']);
            $item->forceFill(['added_reason' => mb_substr("Added because page {$r['page']} of {$doc->filename} {$r['because']}.", 0, 300)])->save();
            app(AuditLogger::class)->record('transactions.item.required', $deal, metadata: ['item' => $r['label'], 'because' => $r['because'], 'document' => $doc->filename], label: $deal->street);
        }
    }

    /**
     * Answers a question only from this document's pages, with checked quotes.
     *
     * @return array{found: bool, answer: string, citations: list<array{page: int, quote: string}>}
     */
    public function ask(Document $doc, string $question, User $by): array
    {
        $reading = DocumentReading::query()->where('document_id', $doc->id)->where('status', 'read')->first();
        if ($reading === null || implode('', (array) $reading->page_text) === '') {
            throw ValidationException::withMessages(['question' => 'This document has not been read yet, or has no readable text.']);
        }
        $pages = (array) $reading->page_text;
        $result = $this->gateway->run(new AiTask(self::ASK, 'docs.ask_document', 'docs-ask:'.$doc->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['question' => mb_substr(trim($question), 0, 500)], untrustedContent: self::pagesForAi($pages), outputSchema: self::ASK_SCHEMA, userId: $by->id));
        $citations = [];
        foreach ((array) ($result->json['citations'] ?? []) as $c) {
            $page = (int) ($c['page'] ?? 0);
            $quote = trim((string) ($c['quote'] ?? ''));
            if ($quote !== '' && self::onPage($quote, $pages[$page - 1] ?? '')) {
                $citations[] = ['page' => $page, 'quote' => mb_substr($quote, 0, 200)];
            }
        }
        $found = (bool) ($result->json['found'] ?? false) && $citations !== [];

        return ['found' => $found, 'answer' => $found ? mb_substr(trim((string) ($result->json['answer'] ?? '')), 0, 800) : 'I could not find that in this document.',
            'citations' => $found ? $citations : []];
    }

    /**
     * Applies chosen facts to the deal (price, contract and closing dates) and its key dates.
     *
     * @param  list<string>  $names
     */
    public function apply(Deal $deal, array $names, User $by): int
    {
        $facts = self::dealFacts($deal);
        $applied = 0;
        $db = DB::connection(Connections::cell());
        foreach ($facts as $f) {
            if (! in_array($f['name'], $names, true)) {
                continue;
            }
            $date = in_array($f['name'], self::DATE_FACTS, true) ? Summaries::date(self::firstDate($f['value']) ?? '') : null;
            if ($f['name'] === 'price' && ($cents = self::cents($f['value'])) !== null) {
                $deal->update(['price_cents' => $cents]);
            } elseif (in_array($f['name'], ['contract_date', 'closing_date'], true) && $date !== null) {
                $deal->update([$f['name'] => $date]);
            } elseif ($date !== null && ! $db->table('tx_dates')->where('deal_id', $deal->id)->where('label', self::FACT_LABELS[$f['name']])->where('due_on', $date)->exists()) {
                $db->table('tx_dates')->insert(['tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id, 'label' => self::FACT_LABELS[$f['name']], 'due_on' => $date,
                    'source' => 'docs', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                continue;
            }
            $applied++;
        }
        app(AuditLogger::class)->record('transactions.facts.applied', $deal, metadata: ['facts' => $names, 'by' => $by->id], label: $deal->street);
        app(ComplianceScan::class)->run($deal);

        return $applied;
    }

    /**
     * The facts found across the deal's documents (newest document wins), with where each came from.
     *
     * @return list<array{name: string, label: string, value: string, page: int, quote: string, document: string, document_ulid: string, applicable: bool}>
     */
    public static function dealFacts(Deal $deal): array
    {
        $out = [];
        $docs = Document::query()->where('deal_id', $deal->id)->whereNull('split_into')->orderBy('id')->get()->keyBy('id');
        foreach (DocumentReading::query()->whereIn('document_id', $docs->keys())->where('status', 'read')->orderBy('document_id')->get() as $r) {
            foreach ((array) $r->facts as $f) {
                $doc = $docs->get($r->document_id);
                if ($doc === null) {
                    continue;
                }
                $applicable = $f['name'] === 'price' ? self::cents($f['value']) !== null
                    : (in_array($f['name'], self::DATE_FACTS, true) && Summaries::date(self::firstDate($f['value']) ?? '') !== null);
                $out[$f['name']] = $f + ['label' => self::FACT_LABELS[$f['name']] ?? $f['name'], 'document' => (string) $doc->filename, 'document_ulid' => (string) $doc->ulid,
                    'applicable' => $applicable];
            }
        }

        return array_values($out);
    }

    /** A copy of the document with Social Security, bank account and birth-date numbers blacked out. */
    public function safeCopy(Document $doc): string
    {
        $reading = DocumentReading::query()->where('document_id', $doc->id)->first();
        $bytes = (string) Storage::disk('local')->get($doc->path);

        return SafeCopy::make($bytes, $doc->mime, (array) ($reading->methods ?? []));
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    private static function pagesForAi(array $pages): array
    {
        $out = [];
        foreach (array_slice($pages, 0, 40) as $i => $p) {
            $out[] = '[Page '.($i + 1)."]\n".mb_substr($p, 0, 6000);
        }

        return $out;
    }

    /** The quote, ignoring spacing and case, is on the page. */
    public static function onPage(string $quote, string $page): bool
    {
        $norm = fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));

        return mb_strlen($norm($quote)) >= 3 && str_contains($norm($page), $norm($quote));
    }

    /** The quote states the value: every number in the value appears in the quote, or the words do. */
    private static function states(string $quote, string $value): bool
    {
        preg_match_all('/\d[\d,.]*/', $value, $m);
        $digits = fn (string $s): string => (string) preg_replace('/\D/', '', (string) preg_replace('/[.,]00$/', '', $s));
        $q = $digits($quote) !== '' ? (string) preg_replace('/\D/', ' ', (string) preg_replace('/(\d)[,.](?=\d{3}\b)/', '$1', $quote)) : '';
        if ($m[0] !== []) {
            foreach ($m[0] as $n) {
                if (! str_contains(' '.$q.' ', ' '.$digits($n).' ') && ! str_contains($digits($quote), $digits($n))) {
                    return false;
                }
            }

            return true;
        }

        return str_contains(mb_strtolower($quote), mb_strtolower(mb_substr($value, 0, 40)));
    }

    private static function cents(string $value): ?int
    {
        return preg_match('/\$?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{2}))?/', $value, $m) === 1 ? (int) str_replace(',', '', $m[1]) * 100 + (int) ($m[2] ?? 0) : null;
    }

    private static function firstDate(string $value): ?string
    {
        return preg_match('~\b\d{1,2}/\d{1,2}/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b|\b(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\b~', $value, $m) === 1
            ? $m[0] : null;
    }

    private static function pages(string $pdf, int $from, int $to): string
    {
        $file = Pdf::tempFile('split-', Pdf::normalise($pdf)[0]);
        try {
            $out = new Fpdi('P', 'pt');
            $out->setSourceFile($file);
            for ($i = $from; $i <= $to; $i++) {
                $tpl = $out->importPage($i);
                $size = $out->getTemplateSize($tpl);
                if (! is_array($size)) {
                    continue;
                }
                $out->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $out->useTemplate($tpl);
            }

            return $out->Output('S');
        } finally {
            @unlink($file);
        }
    }
}
