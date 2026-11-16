<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Deals;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Timeline\Timeline;

/**
 * Start a deal any way (specs/ai/02, §2): upload the contract or a phone photo of it, or say or type "new deal at 14 Oak
 * St". Deal-IQ creates the deal, reads what it can, and shows a setup page where the agent confirms the address,
 * people, price, dates, the home and the financing. Confirming fills the parties, adapts the checklist and builds the
 * timeline. Nothing is assumed silently.
 */
final class Intake
{
    public const FEATURE = 'docs.intake';

    public function __construct(private readonly Deals $deals) {}

    public function fromFile(UploadedFile $file, User $agent, User $by): Deal
    {
        $deal = $this->deals->create(['type' => 'buyer', 'street' => 'New deal (reading the contract…)', 'state_code' => ''], $agent, $by);
        $deal->update(['setup_status' => 'reading']);
        app(Documents::class)->upload($deal, null, $file, str_starts_with((string) $file->getMimeType(), 'image/') ? 'photo' : 'upload', $by);

        return $deal;
    }

    public function fromText(string $text, User $agent, User $by): Deal
    {
        $r = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'transactions.deal_intake', 'deal-intake:'.$by->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['text' => mb_substr(trim($text), 0, 1000)], outputSchema: ['type' => 'object', 'required' => ['street'], 'properties' => ['street' => ['type' => 'string']]], userId: $by->id));
        $j = (array) $r->json;
        $s = fn (string $k, int $max = 160): ?string => is_string($j[$k] ?? null) && trim($j[$k]) !== '' && trim($j[$k]) !== 'empty' ? mb_substr(trim($j[$k]), 0, $max) : null;
        $date = fn (string $k): ?string => ($v = $s($k)) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $type = in_array($s('type'), Deals::TYPES, true) ? (string) $s('type') : 'buyer';
        $deal = $this->deals->create(['type' => $type, 'street' => $s('street') ?? 'New deal', 'city' => $s('city', 80), 'state_code' => strtoupper((string) $s('state', 2)),
            'postal_code' => $s('postal_code', 10), 'price_cents' => is_numeric($j['price'] ?? null) ? (int) round((float) $j['price'] * 100) : 0], $agent, $by);
        $deal->update(['setup_status' => 'review', 'contract_date' => $date('contract_date'), 'closing_date' => $date('closing_date'),
            'financing' => array_key_exists((string) $s('financing'), Adapt::FINANCING) ? $s('financing') : null,
            'property_type' => array_key_exists((string) $s('property_type'), Adapt::PROPERTY_TYPES) ? $s('property_type') : null]);
        foreach (['buyer' => 'buyers', 'seller' => 'sellers'] as $role => $key) {
            foreach (array_slice(array_filter((array) ($j[$key] ?? []), 'is_string'), 0, 4) as $name) {
                $this->party($deal, $role, $name);
            }
        }

        return $deal;
    }

    /**
     * What the setup page proposes: the agent's typed details, or what DocIQ read from the contract.
     *
     * @return array<string, mixed>
     */
    public function proposal(Deal $deal): array
    {
        $facts = collect(DocIq::dealFacts($deal))->keyBy('name');
        $reading = DocumentReading::query()->whereIn('document_id', Document::query()->where('deal_id', $deal->id)->pluck('id'))->latest('id')->first();
        $v = fn (string $k): ?string => isset($facts[$k]) ? (string) $facts[$k]['value'] : null;
        $address = self::address($v('property_address') ?? '');
        $names = fn (?string $s): array => $s === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|\band\b|&)\s*/i', $s) ?: [])));
        $parties = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->get(['role', 'name', 'email']);
        $proposed = $parties->isNotEmpty() ? $parties->map(fn ($p): array => ['role' => (string) $p->role, 'name' => (string) $p->name, 'email' => (string) ($p->email ?? '')])->values()->all()
            : array_merge(array_map(fn (string $n): array => ['role' => 'buyer', 'name' => $n, 'email' => ''], $names($v('buyer_names'))),
                array_map(fn (string $n): array => ['role' => 'seller', 'name' => $n, 'email' => ''], $names($v('seller_names'))),
                $v('lender') !== null ? [['role' => 'lender', 'name' => (string) $v('lender'), 'email' => '']] : [],
                $v('title_company') !== null ? [['role' => 'title', 'name' => (string) $v('title_company'), 'email' => '']] : []);

        return [
            'reading' => $deal->setup_status === 'reading' && ($reading === null || in_array($reading->status, ['queued', 'reading'], true)),
            'read_failed' => $reading !== null && $reading->status === 'failed',
            'type' => $deal->type,
            'street' => $deal->setup_status === 'reading' || str_starts_with($deal->street, 'New deal') ? ($address['street'] ?? '') : $deal->street,
            'city' => $deal->city ?? ($address['city'] ?? ''), 'state_code' => $deal->state_code !== '' ? $deal->state_code : ($address['state'] ?? ''),
            'postal_code' => $deal->postal_code ?? ($address['zip'] ?? ''),
            'price' => $deal->price_cents > 0 ? (string) ($deal->price_cents / 100) : (($c = DocIq::centsOf((string) $v('price'))) !== null ? (string) ($c / 100) : ''),
            'contract_date' => $deal->contract_date?->toDateString() ?? DocIq::dateOf((string) $v('contract_date')) ?? '',
            'closing_date' => $deal->closing_date?->toDateString() ?? DocIq::dateOf((string) $v('closing_date')) ?? '',
            'property_type' => $deal->property_type ?? '', 'financing' => $deal->financing ?? '',
            'parties' => $proposed,
            'facts' => $facts->values()->map(fn (array $f): array => ['label' => $f['label'], 'value' => $f['value'], 'page' => $f['page'], 'quote' => $f['quote']])->all(),
        ];
    }

    /**
     * The agent confirms the setup.
     *
     * @param  array{type: string, street: string, city?: string|null, state_code: string, postal_code?: string|null, price?: float|string|null, contract_date?: string|null, closing_date?: string|null, property_type?: string|null, financing?: string|null, parties?: list<array{role: string, name: string, email?: string|null}>}  $data
     * @return array{checklist: list<string>, dates: int}
     */
    public function confirm(Deal $deal, array $data, User $by): array
    {
        if (! in_array($data['type'], Deals::TYPES, true) || trim($data['street']) === '' || strlen(trim($data['state_code'])) !== 2) {
            throw ValidationException::withMessages(['street' => 'Give the deal type, street and state.']);
        }
        $deal->update(['type' => $data['type'], 'street' => mb_substr(trim($data['street']), 0, 160), 'city' => $data['city'] ?? null, 'state_code' => strtoupper(trim($data['state_code'])),
            'postal_code' => $data['postal_code'] ?? null, 'price_cents' => is_numeric($data['price'] ?? null) ? (int) round((float) $data['price'] * 100) : $deal->price_cents,
            'contract_date' => ($data['contract_date'] ?? '') !== '' ? $data['contract_date'] : null, 'closing_date' => ($data['closing_date'] ?? '') !== '' ? $data['closing_date'] : null,
            'property_type' => array_key_exists((string) ($data['property_type'] ?? ''), Adapt::PROPERTY_TYPES) ? ($data['property_type'] ?? null) : null,
            'financing' => array_key_exists((string) ($data['financing'] ?? ''), Adapt::FINANCING) ? ($data['financing'] ?? null) : null, 'setup_status' => null]);
        $db = DB::connection(Connections::cell());
        foreach (array_slice($data['parties'] ?? [], 0, 12) as $p) {
            if (trim($p['name']) !== '' && ! $db->table('tx_parties')->where('deal_id', $deal->id)->where('role', $p['role'])->where('name', trim($p['name']))->exists()) {
                $this->party($deal, $p['role'], $p['name'], $p['email'] ?? null);
            }
        }
        $added = app(Adapt::class)->apply($deal);
        $dates = app(Timeline::class)->rebuild($deal);
        app(AuditLogger::class)->record('transactions.deal.set_up', $deal, metadata: ['checklist_added' => $added, 'dates' => count($dates)], label: $deal->street);

        return ['checklist' => $added, 'dates' => count($dates)];
    }

    /**
     * "14 Oak St, Austin, TX 78701" → parts.
     *
     * @return array{street?: string, city?: string, state?: string, zip?: string}
     */
    public static function address(string $text): array
    {
        if (preg_match('/^\s*(.+?),\s*([^,]+?),\s*([A-Z]{2})\s*(\d{5})?/', $text, $m) === 1) {
            return array_filter(['street' => trim($m[1]), 'city' => trim($m[2]), 'state' => $m[3], 'zip' => $m[4] ?? null]);
        }

        return trim($text) !== '' ? ['street' => mb_substr(trim($text), 0, 160)] : [];
    }

    private function party(Deal $deal, string $role, string $name, ?string $email = null): void
    {
        $role = in_array($role, ['buyer', 'seller', 'co_op_agent', 'lender', 'title', 'escrow', 'attorney', 'inspector', 'other'], true) ? $role : 'other';
        DB::connection(Connections::cell())->table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => $role, 'name' => mb_substr(trim($name), 0, 160),
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
