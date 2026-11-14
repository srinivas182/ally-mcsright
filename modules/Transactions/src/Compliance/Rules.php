<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;

/**
 * The brokerage's own rules in plain English ("Listings over $1M need broker approval"). AI turns each into a
 * fixed structure, which RightAlly shows back in plain words; the rule applies only once the broker confirms
 * that reading. Rules are then evaluated by code on every scan, like the built-in checks.
 */
final class Rules
{
    public const FEATURE = 'compliance.rule';

    private const DEAL_TYPES = ['listing', 'buyer', 'dual', 'lease', 'referral', 'new_construction'];

    private const SCHEMA = ['type' => 'object', 'required' => ['applies_to', 'requires', 'severity', 'summary'], 'properties' => [
        'applies_to' => ['type' => 'object'], 'requires' => ['type' => 'object'], 'severity' => ['type' => 'string'], 'summary' => ['type' => 'string']]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants) {}

    /** Reads a plain-English rule and stores it unconfirmed, with RightAlly's reading of it. */
    public function draft(string $text, User $by): string
    {
        $text = trim($text);
        if (mb_strlen($text) < 10) {
            throw ValidationException::withMessages(['rule' => 'Write the rule as a full sentence.']);
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'docs.compliance_rule', 'rule:'.sha1($text).':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['rule' => mb_substr($text, 0, 500)], outputSchema: self::SCHEMA, userId: $by->id));
        $rule = self::clean((array) $result->json);
        if ($rule['requires']['kind'] === 'unsupported') {
            throw ValidationException::withMessages(['rule' => 'RightAlly cannot check that rule automatically yet: '.$rule['summary']]);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_compliance_rules')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'text' => mb_substr($text, 0, 500),
            'rule' => json_encode($rule), 'summary' => self::describe($rule), 'active' => false, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    public function confirm(string $ulid, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_compliance_rules')->where('ulid', $ulid)->update(['active' => true, 'confirmed_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.compliance.rule', metadata: ['rule' => $ulid, 'by' => $by->id], severity: 'notice');
    }

    public function deactivate(string $ulid): void
    {
        DB::connection(Connections::cell())->table('tx_compliance_rules')->where('ulid', $ulid)->update(['active' => false, 'updated_at' => now()]);
    }

    public function approve(Deal $deal, string $ruleUlid, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $rule = $db->table('tx_compliance_rules')->where('ulid', $ruleUlid)->where('active', true)->first() ?? throw ValidationException::withMessages(['rule' => 'Rule not found.']);
        $db->table('tx_rule_approvals')->updateOrInsert(['deal_id' => $deal->id, 'rule_id' => $rule->id],
            ['tenant_id' => $this->tenants->id(), 'approved_by' => $by->id, 'approved_at' => now()]);
        app(AuditLogger::class)->record('transactions.compliance.approved', $deal, metadata: ['rule' => $rule->summary], severity: 'notice', label: $deal->street);
    }

    /**
     * Findings for the brokerage's confirmed rules on one deal.
     *
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, DocumentReading>  $readings
     * @return list<array{code: string, severity: string, key: string, message: string, fix: ?array<string, mixed>}>
     */
    public static function evaluate(Deal $deal, $items, $readings): array
    {
        $db = DB::connection(Connections::cell());
        $text = null;
        $out = [];
        foreach ($db->table('tx_compliance_rules')->where('active', true)->orderBy('id')->get() as $row) {
            $rule = (array) json_decode((string) $row->rule, true);
            $when = (array) ($rule['applies_to'] ?? []);
            if (($when['deal_types'] ?? []) !== [] && ! in_array($deal->type, (array) $when['deal_types'], true)) {
                continue;
            }
            if (($when['states'] ?? []) !== [] && ! in_array($deal->state_code, (array) $when['states'], true)) {
                continue;
            }
            $price = (int) $deal->price_cents / 100;
            if (($when['min_price'] ?? null) !== null && $price <= (float) $when['min_price'] || ($when['max_price'] ?? null) !== null && $price >= (float) $when['max_price']) {
                continue;
            }
            if (($when['text_contains'] ?? []) !== []) {
                $text ??= mb_strtolower(implode("\n", $readings->flatMap(fn (DocumentReading $r): array => (array) $r->page_text)->all()));
                if (! collect((array) $when['text_contains'])->contains(fn ($w): bool => str_contains($text, mb_strtolower((string) $w)))) {
                    continue;
                }
            }
            $severity = in_array($rule['severity'] ?? '', ['blocker', 'warning'], true) ? $rule['severity'] : 'warning';
            if (($rule['requires']['kind'] ?? '') === 'broker_approval') {
                if (! $db->table('tx_rule_approvals')->where('deal_id', $deal->id)->where('rule_id', $row->id)->exists()) {
                    $out[] = ['code' => 'rule', 'severity' => $severity, 'key' => 'rule:'.$row->ulid, 'message' => 'Brokerage rule: '.$row->summary,
                        'fix' => ['action' => 'approve_rule', 'label' => 'Approve (broker)', 'rule' => $row->ulid]];
                }
            } elseif (($doc = trim((string) ($rule['requires']['document'] ?? ''))) !== '' && ! self::hasDocument($doc, $items, $readings)) {
                $item = self::itemFor($doc, $items);
                $out[] = ['code' => 'rule', 'severity' => $severity, 'key' => 'rule:'.$row->ulid, 'message' => 'Brokerage rule: '.$row->summary,
                    'fix' => $item !== null ? ['action' => 'upload', 'label' => 'Upload', 'item' => $item->ulid] : null];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{applies_to: array{deal_types: list<string>, min_price: ?float, max_price: ?float, states: list<string>, text_contains: list<string>}, requires: array{kind: string, document: ?string}, severity: string, summary: string}
     */
    public static function clean(array $json): array
    {
        $when = (array) ($json['applies_to'] ?? []);
        $requires = (array) ($json['requires'] ?? []);
        $num = fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
        $kind = in_array($requires['kind'] ?? '', ['document', 'broker_approval', 'unsupported'], true) ? (string) $requires['kind'] : 'unsupported';

        return [
            'applies_to' => [
                'deal_types' => array_values(array_intersect(array_map('strval', (array) ($when['deal_types'] ?? [])), self::DEAL_TYPES)),
                'min_price' => $num($when['min_price'] ?? null), 'max_price' => $num($when['max_price'] ?? null),
                'states' => array_values(array_filter(array_map(fn ($s): string => strtoupper(substr((string) $s, 0, 2)), (array) ($when['states'] ?? [])), fn (string $s): bool => preg_match('/^[A-Z]{2}$/', $s) === 1)),
                'text_contains' => array_values(array_filter(array_map(fn ($w): string => mb_substr(trim((string) $w), 0, 40), (array) ($when['text_contains'] ?? [])))),
            ],
            'requires' => ['kind' => $kind === 'document' && trim((string) ($requires['document'] ?? '')) === '' ? 'unsupported' : $kind,
                'document' => $kind === 'document' ? mb_substr(trim((string) $requires['document']), 0, 120) : null],
            'severity' => ($json['severity'] ?? '') === 'blocker' ? 'blocker' : 'warning',
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 280),
        ];
    }

    /**
     * RightAlly's reading of the rule in plain words, built by code from the structure (not the AI's summary),
     * so the broker confirms exactly what will be checked.
     *
     * @param  array{applies_to: array<string, mixed>, requires: array{kind: string, document: ?string}, severity: string, summary: string}  $rule
     */
    public static function describe(array $rule): string
    {
        $w = $rule['applies_to'];
        $deals = $w['deal_types'] === [] ? 'every deal' : implode(' or ', array_map(fn (string $t): string => str_replace('_', ' ', $t), $w['deal_types'])).' deals';
        $parts = [];
        if ($w['min_price'] !== null) {
            $parts[] = 'over $'.number_format((float) $w['min_price']);
        }
        if ($w['max_price'] !== null) {
            $parts[] = 'under $'.number_format((float) $w['max_price']);
        }
        if ($w['states'] !== []) {
            $parts[] = 'in '.implode(', ', $w['states']);
        }
        if ($w['text_contains'] !== []) {
            $parts[] = 'whose documents mention '.implode(' or ', array_map(fn (string $t): string => "“{$t}”", $w['text_contains']));
        }
        $need = $rule['requires']['kind'] === 'broker_approval' ? 'need broker approval' : 'need '.$rule['requires']['document'];

        return mb_substr(ucfirst(trim($deals.' '.implode(', ', $parts))).' '.$need.' ('.($rule['severity'] === 'blocker' ? 'blocks the file' : 'warning').').', 0, 300);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, DocumentReading>  $readings
     */
    private static function hasDocument(string $name, $items, $readings): bool
    {
        $item = self::itemFor($name, $items);
        if ($item !== null && in_array($item->status, ['submitted', 'approved', 'waived'], true)) {
            return true;
        }
        $words = self::words($name);

        return $readings->contains(fn (DocumentReading $r): bool => $words !== [] && collect($words)->every(fn (string $w): bool => str_contains(mb_strtolower((string) $r->title), $w)));
    }

    /**
     * @param  Collection<int, Item>  $items
     */
    private static function itemFor(string $name, $items): ?Item
    {
        $words = self::words($name);

        return $words === [] ? null : $items->first(fn (Item $i): bool => collect($words)->every(fn (string $w): bool => str_contains(mb_strtolower($i->label), $w)));
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($name)) ?: [], fn (string $w): bool => mb_strlen($w) >= 4 && ! in_array($w, ['with', 'from', 'signed', 'copy'], true)));
    }
}
