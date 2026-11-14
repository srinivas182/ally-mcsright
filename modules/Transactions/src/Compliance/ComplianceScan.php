<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Summaries;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\DocTypes;

/**
 * The continuous compliance scan (specs/ai/02, Sprint 15B). Runs whenever a deal's file changes: a document is
 * uploaded or read, a checklist item is reviewed, the status changes or facts are applied. Every check is plain
 * code over the documents DocIQ read and over RightAlly's own records (roster licences and E&O, form editions,
 * identity overrides), so the same file always gets the same findings, each with its evidence and a fix.
 *
 * Findings keep their identity across scans (a fingerprint): a problem that is fixed is resolved, a problem
 * the broker dismissed stays dismissed, and both feed each check's accuracy.
 */
final class ComplianceScan
{
    /** Contract-type documents a broker expects to carry an e-signature record when signed elsewhere. */
    private const SIGNED_TYPES = ['purchase_agreement', 'counter_offer', 'amendment', 'listing_agreement', 'buyer_agreement'];

    private const ESIGN_MARKS = '/docusign envelope id|dotloop (verified|signature verification)|authentisign|signnow|adobe (acrobat )?sign|digisign|zipsign|electronically signed|signature certificate|certificate of completion/i';

    /** @var list<array{code: string, severity: string, key: string, message: string, evidence: ?array<string, mixed>, fix: ?array<string, mixed>}> */
    private array $found = [];

    /** @var array<string, array{enabled: bool, severity: string}> */
    private array $settings = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Scans one deal and stores its findings and broker-ready score.
     *
     * @return array{score: int, blockers: int, warnings: int}
     */
    public function run(Deal $deal): array
    {
        $this->found = [];
        $this->settings = Checks::settings();
        $deal->refresh();
        $docs = Document::query()->where('deal_id', $deal->id)->whereNull('split_into')->orderBy('id')->get();
        $readings = DocumentReading::query()->whereIn('document_id', $docs->pluck('id'))->where('status', 'read')->get()->keyBy('document_id');
        $items = Item::query()->where('deal_id', $deal->id)->orderBy('sort')->get();

        $this->checklist($deal, $items);
        foreach ($docs as $doc) {
            $reading = $readings->get($doc->id);
            if ($reading !== null) {
                $this->document($deal, $doc, $reading);
            }
        }
        $this->mismatches($deal, $docs, $readings);
        $this->buyerAgreement($deal, $items, $readings);
        $this->agent($deal);
        $this->forms($deal);
        foreach (Rules::evaluate($deal, $items, $readings) as $f) {
            $this->add($f['code'], $f['severity'], $f['key'], $f['message'], null, $f['fix']);
        }

        return $this->store($deal);
    }

    /**
     * @param  Collection<int, Item>  $items
     */
    private function checklist(Deal $deal, $items): void
    {
        $stages = match (true) {
            $deal->status === 'closed' => ['listing', 'contract', 'closing'],
            $deal->status === 'under_contract' => array_merge(['listing', 'contract'], $deal->closing_date !== null && $deal->closing_date->lte(CarbonImmutable::today()->addDays(7)) ? ['closing'] : []),
            default => ['listing'],
        };
        foreach ($items as $item) {
            if ($item->required && $item->status === 'missing' && in_array($item->stage, $stages, true)) {
                $this->add('checklist.missing', null, 'item:'.$item->ulid, "Missing: {$item->label}.".($item->getAttribute('added_reason') !== null ? ' '.$item->getAttribute('added_reason') : ''),
                    null, ['action' => 'upload', 'label' => 'Upload', 'item' => $item->ulid]);
            }
            if ($item->status === 'needs_fix') {
                $this->add('checklist.needs_fix', null, 'fix:'.$item->ulid, "{$item->label} was sent back: {$item->fix_reason}", null, ['action' => 'upload', 'label' => 'Upload the fixed version', 'item' => $item->ulid]);
            }
        }
    }

    private function document(Deal $deal, Document $doc, DocumentReading $reading): void
    {
        $pages = (array) $reading->page_text;
        $blanks = 0;
        foreach ($pages as $i => $text) {
            foreach (explode("\n", $text) as $line) {
                if ($blanks < 3 && preg_match('/\b(price|purchase price|sales price|closing date|effective date|earnest money|amount|buyer\'?s? name|seller\'?s? name|property address)\b[^\n_]{0,25}_{5,}/i', $line, $m) === 1
                    && preg_match('/\b(sign|signature|initials?)\b/i', $line) !== 1) {
                    $blanks++;
                    $this->add('docs.blank', null, "blank:{$doc->ulid}:".($i + 1).':'.md5($m[0]), "Looks blank in {$doc->filename}: “".trim($m[0]).'”',
                        self::evidence($doc, $i + 1, trim($m[0])), ['action' => 'view', 'label' => 'See the page', 'document' => $doc->ulid, 'page' => $i + 1]);
                }
            }
        }
        $year = (int) ($deal->contract_date ?? CarbonImmutable::parse((string) $deal->getAttribute('created_at')))->format('Y');
        foreach ((array) $reading->facts as $f) {
            $date = Summaries::date((string) (preg_match('~\b\d{1,2}/\d{1,2}/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b~', $f['value'], $m) === 1 ? $m[0] : ''));
            if ($date !== null && abs((int) substr($date, 0, 4) - $year) > 1) {
                $this->add('docs.wrong_year', null, "year:{$doc->ulid}:{$f['name']}", "{$doc->filename} gives {$f['value']} for ".str_replace('_', ' ', $f['name']).", far from {$year}. Check the year.",
                    self::evidence($doc, $f['page'], $f['quote']), ['action' => 'view', 'label' => 'See the page', 'document' => $doc->ulid, 'page' => $f['page']]);
            }
        }
        if (in_array($reading->doc_type, self::SIGNED_TYPES, true) && ! in_array($doc->via, ['esign'], true)
            && ! ($doc->getAttribute('parent_document_id') !== null && Document::query()->whereKey($doc->getAttribute('parent_document_id'))->value('via') === 'esign')
            && preg_match(self::ESIGN_MARKS, implode("\n", $pages)) !== 1) {
            $this->add('docs.no_signature_record', null, "esign:{$doc->ulid}", "{$doc->filename} has no e-signature record. Check every signature and initial by eye.",
                null, ['action' => 'view', 'label' => 'Open', 'document' => $doc->ulid, 'page' => 1]);
        }
        $s = (array) $reading->sensitive;
        if ((bool) ($s['wire'] ?? false)) {
            $this->add('docs.wire', null, "wire:{$doc->ulid}", "{$doc->filename} contains wiring or bank instructions. Never forward it; confirm payment details by phone on a known number.",
                null, ['action' => 'safe_copy', 'label' => 'Safe copy', 'document' => $doc->ulid]);
        }
        if (((int) ($s['ssn'] ?? 0)) + ((int) ($s['bank'] ?? 0)) + ((int) ($s['dob'] ?? 0)) > 0) {
            $this->add('docs.sensitive', null, "sensitive:{$doc->ulid}", "{$doc->filename} holds Social Security, bank or birth-date numbers. Share only the safe copy.",
                null, ['action' => 'safe_copy', 'label' => 'Safe copy', 'document' => $doc->ulid]);
        }
    }

    /**
     * @param  Collection<int, Document>  $docs
     * @param  Collection<int, DocumentReading>  $readings
     */
    private function mismatches(Deal $deal, $docs, $readings): void
    {
        $parties = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->get();
        foreach ($docs as $doc) {
            $reading = $readings->get($doc->id);
            if ($reading === null || in_array($reading->doc_type, ['counter_offer', 'amendment'], true)) {
                continue; // counters and amendments change terms on purpose
            }
            foreach ((array) $reading->facts as $f) {
                $evidence = self::evidence($doc, $f['page'], $f['quote']);
                $view = ['action' => 'view', 'label' => 'See the page', 'document' => $doc->ulid, 'page' => $f['page']];
                if ($f['name'] === 'price' && $deal->price_cents > 0 && ($cents = self::cents($f['value'])) !== null && $cents !== (int) $deal->price_cents) {
                    $this->add('docs.mismatch', null, "price:{$doc->ulid}", "{$doc->filename} says the price is ".self::usd($cents).'; the deal says '.self::usd((int) $deal->price_cents).'.',
                        $evidence, ['action' => 'apply_fact', 'label' => 'Use '.self::usd($cents), 'fact' => 'price', 'document' => $doc->ulid]);
                }
                if ($f['name'] === 'closing_date' && $deal->closing_date !== null && ($date = Summaries::date((string) $f['value'])) !== null && $date !== $deal->closing_date->toDateString()) {
                    $this->add('docs.mismatch', null, "closing:{$doc->ulid}", "{$doc->filename} gives closing on {$f['value']}; the deal says ".$deal->closing_date->format('m/d/Y').'.',
                        $evidence, ['action' => 'apply_fact', 'label' => 'Use '.$f['value'], 'fact' => 'closing_date', 'document' => $doc->ulid]);
                }
                if ($f['name'] === 'property_address' && ! self::sameStreet((string) $f['value'], (string) $deal->street)) {
                    $this->add('docs.mismatch', null, "address:{$doc->ulid}", "{$doc->filename} names the property as “{$f['value']}”; the deal is {$deal->street}.", $evidence, $view);
                }
                foreach (['seller_names' => 'seller', 'buyer_names' => 'buyer'] as $fact => $role) {
                    $people = $parties->where('role', $role)->pluck('name')->filter()->values();
                    if ($f['name'] === $fact && $people->isNotEmpty()) {
                        $missing = $people->filter(fn ($n): bool => ! self::namedIn((string) $n, (string) $f['value']))->values();
                        if ($missing->isNotEmpty()) {
                            $this->add('docs.mismatch', null, "{$role}s:{$doc->ulid}", ucfirst($role)."s in {$doc->filename} are “{$f['value']}”, which does not include ".$missing->implode(' and ').' from the deal.', $evidence, $view);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, DocumentReading>  $readings
     */
    private function buyerAgreement(Deal $deal, $items, $readings): void
    {
        if (! in_array($deal->type, ['buyer', 'dual'], true) || $deal->status === 'pre_contract') {
            return;
        }
        $onFile = $readings->contains(fn (DocumentReading $r): bool => $r->doc_type === 'buyer_agreement')
            || $items->contains(fn (Item $i): bool => str_contains(mb_strtolower($i->label), 'buyer') && str_contains(mb_strtolower($i->label), 'agreement') && in_array($i->status, ['submitted', 'approved'], true));
        if (! $onFile) {
            $item = $items->first(fn (Item $i): bool => str_contains(mb_strtolower($i->label), 'buyer') && str_contains(mb_strtolower($i->label), 'agreement'));
            $this->add('docs.buyer_agreement', null, 'buyer-agreement', 'No signed buyer agreement is on file. Since the NAR settlement, one is needed before touring.',
                null, $item !== null ? ['action' => 'upload', 'label' => 'Upload', 'item' => $item->ulid] : null);
        }
    }

    private function agent(Deal $deal): void
    {
        $db = DB::connection(Connections::cell());
        $on = ($deal->contract_date ?? CarbonImmutable::today())->toDateString();
        $agent = User::query()->find($deal->agent_user_id);
        $name = $agent?->name ?: 'The agent';
        $roster = ['action' => 'roster', 'label' => 'Open the roster', 'user' => $agent?->ulid];
        $licence = $db->table('roster_credentials')->where('user_id', $deal->agent_user_id)->where('kind', 'licence')->where('status', 'current')
            ->where(fn ($q) => $q->where('state_code', $deal->state_code)->orWhereNull('state_code'))->orderByDesc('expires_on')->first();
        if ($licence === null) {
            $this->add('agent.licence', null, 'licence', "{$name} has no {$deal->state_code} licence on the roster.", null, $roster);
        } elseif ($licence->expires_on !== null && substr((string) $licence->expires_on, 0, 10) < $on) {
            $this->add('agent.licence', null, 'licence', "{$name}'s {$deal->state_code} licence expired on ".CarbonImmutable::parse((string) $licence->expires_on)->format('m/d/Y').', before '.CarbonImmutable::parse($on)->format('m/d/Y').'.', null, $roster);
        }
        $required = (bool) ($db->table('roster_settings')->value('eo_required') ?? true);
        if ($required) {
            $eo = $db->table('roster_credentials')->where('user_id', $deal->agent_user_id)->where('kind', 'eo')->where('status', 'current')->orderByDesc('expires_on')->first();
            if ($eo === null || ($eo->expires_on !== null && substr((string) $eo->expires_on, 0, 10) < $on)) {
                $this->add('agent.eo', null, 'eo', $eo === null ? "{$name} has no E&O cover on the roster." : "{$name}'s E&O cover expired on ".CarbonImmutable::parse((string) $eo->expires_on)->format('m/d/Y').'.', null, $roster);
            }
        }
    }

    private function forms(Deal $deal): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($db->table('form_packets')->where('deal_id', $deal->id)->whereIn('status', ['sent', 'completed'])->get() as $p) {
            foreach ($db->table('form_packet_forms')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')->where('form_packet_forms.packet_id', $p->id)
                ->whereNotNull('form_templates.superseded_by')->get(['form_templates.name', 'form_templates.superseded_by']) as $f) {
                $newer = $db->table('form_templates')->where('id', $f->superseded_by)->first();
                if ($newer !== null && $p->sent_at !== null && (string) $newer->created_at < (string) $p->sent_at) {
                    $this->add('forms.old_edition', null, "edition:{$p->ulid}:{$f->name}", "“{$p->title}” was sent on an older edition of {$f->name} (edition {$newer->edition} was already out).", null,
                        ['action' => 'packet', 'label' => 'Open the packet', 'packet' => $p->ulid]);
                }
            }
            if ($p->owner_override_reason !== null) {
                $this->add('forms.owner_override', null, "owner:{$p->ulid}", "The seller is not the owner of record; accepted by the brokerage: {$p->owner_override_reason}", null,
                    ['action' => 'packet', 'label' => 'Open the packet', 'packet' => $p->ulid]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     * @param  array<string, mixed>|null  $fix
     */
    private function add(string $code, ?string $severity, string $key, string $message, ?array $evidence, ?array $fix): void
    {
        $setting = $this->settings[$code] ?? null;
        if ($setting !== null && ! $setting['enabled']) {
            return;
        }
        $this->found[] = ['code' => $code, 'severity' => $severity ?? ($setting['severity'] ?? 'warning'), 'key' => $key, 'message' => mb_substr($message, 0, 400),
            'evidence' => $evidence, 'fix' => $fix];
    }

    /**
     * @return array{score: int, blockers: int, warnings: int}
     */
    private function store(Deal $deal): array
    {
        $db = DB::connection(Connections::cell());
        $now = now();
        $seen = [];
        foreach ($this->found as $f) {
            $fingerprint = sha1($f['code'].'|'.$f['key']);
            $seen[] = $fingerprint;
            $existing = $db->table('tx_findings')->where('deal_id', $deal->id)->where('fingerprint', $fingerprint)->first();
            $values = ['code' => $f['code'], 'severity' => $f['severity'], 'message' => $f['message'], 'evidence' => $f['evidence'] !== null ? json_encode($f['evidence']) : null,
                'fix' => $f['fix'] !== null ? json_encode($f['fix']) : null, 'last_seen_at' => $now];
            if ($existing === null) {
                $db->table('tx_findings')->insert($values + ['tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id, 'fingerprint' => $fingerprint, 'status' => 'open', 'first_seen_at' => $now]);
            } else {
                // A problem that comes back after being fixed is open again; a dismissed one stays dismissed.
                $db->table('tx_findings')->where('id', $existing->id)->update($values + ($existing->status === 'resolved' ? ['status' => 'open', 'resolved_at' => null] : []));
            }
        }
        $db->table('tx_findings')->where('deal_id', $deal->id)->where('status', 'open')->whereNotIn('fingerprint', $seen === [] ? [''] : $seen)
            ->update(['status' => 'resolved', 'resolved_at' => $now]);

        $open = $db->table('tx_findings')->where('deal_id', $deal->id)->where('status', 'open')->get(['severity']);
        $blockers = $open->where('severity', 'blocker')->count();
        $warnings = $open->where('severity', 'warning')->count();
        $score = max(0, 100 - 20 * $blockers - 5 * $warnings);
        $db->table('tx_deals')->where('id', $deal->id)->update(['compliance_score' => $score, 'compliance_scanned_at' => $now]);

        return ['score' => $score, 'blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * @return array{document: string, page: int, quote: string}
     */
    private static function evidence(Document $doc, int $page, string $quote): array
    {
        return ['document' => $doc->ulid, 'filename' => $doc->filename, 'page' => $page, 'quote' => mb_substr($quote, 0, 200)];
    }

    private static function cents(string $value): ?int
    {
        return preg_match('/\$?\s*(\d{1,3}(?:,\d{3})+|\d{4,})(?:\.(\d{2}))?/', $value, $m) === 1 ? (int) str_replace(',', '', $m[1]) * 100 + (int) ($m[2] ?? 0) : null;
    }

    private static function usd(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /** Same street: the house number and the first word of the street name match. */
    private static function sameStreet(string $a, string $b): bool
    {
        $key = function (string $s): string {
            preg_match('/(\d+[a-z]?)\s+([a-z]+)/i', $s, $m);

            return mb_strtolower(($m[1] ?? '').' '.($m[2] ?? ''));
        };

        return trim($key($a)) === '' || trim($key($b)) === '' || $key($a) === $key($b);
    }

    /** The person's last name appears in the list of names. */
    private static function namedIn(string $name, string $list): bool
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $last = mb_strtolower((string) end($parts));

        return $last === '' || str_contains(mb_strtolower($list), $last);
    }

    /** Recognised label for a document type (for messages). */
    public static function typeLabel(?string $type): string
    {
        return DocTypes::label($type);
    }
}
