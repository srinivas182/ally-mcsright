<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Packets (specs/forms/01): one or more forms on a deal, auto-filled from the deal, with the people who will
 * sign. The pre-send check (addition 1) lists everything that would stop a clean signing.
 */
final class Packets
{
    public function __construct(private readonly TenantContext $tenants, private readonly Templates $templates) {}

    /**
     * @param  list<string>  $templateUlids  (a list, in order)
     */
    public function create(Deal $deal, string $title, array $templateUlids, User $by): string
    {
        $this->canWork($deal, $by);
        if ($templateUlids === []) {
            throw ValidationException::withMessages(['templates' => 'Choose at least one form.']);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $deal, $title, $templateUlids, $by, $ulid): void {
            $id = $db->table('form_packets')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id,
                'title' => mb_substr(trim($title) !== '' ? trim($title) : 'Forms for '.$deal->street, 0, 160), 'status' => 'draft', 'created_by' => $by->id,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($templateUlids as $i => $t) {
                $template = $this->templates->find($t) ?? throw ValidationException::withMessages(['templates' => 'A chosen form no longer exists.']);
                $db->table('form_packet_forms')->insert(['tenant_id' => $this->tenants->id(), 'packet_id' => $id, 'template_id' => $template['id'],
                    'template_version' => $template['version'], 'values' => json_encode($this->autofill($deal, $template['fields'])), 'sort' => $i]);
            }
            foreach ($this->suggestedSigners($deal) as $order => $s) {
                $db->table('form_signers')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'packet_id' => $id] + $s
                    + ['sign_order' => $order + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        app(AuditLogger::class)->record('forms.packet.created', $deal, metadata: ['packet' => $ulid, 'forms' => count($templateUlids)], label: $deal->street);

        return $ulid;
    }

    /**
     * @param  array<string, string>  $values  field key => value, for one form in the packet
     */
    public function setValues(string $packetUlid, int $formId, array $values, User $by): void
    {
        [$packet, $deal] = $this->packet($packetUlid, $by);
        if ($packet->status !== 'draft') {
            throw ValidationException::withMessages(['packet' => 'Values can only change before sending.']);
        }
        $db = DB::connection(Connections::cell());
        $form = $db->table('form_packet_forms')->where('id', $formId)->where('packet_id', $packet->id)->first()
            ?? throw ValidationException::withMessages(['form' => 'Form not in this packet.']);
        $fields = collect($this->fieldsFor((int) $form->template_id))->keyBy('key');
        $current = (array) json_decode((string) $form->values, true);
        foreach ($values as $key => $value) {
            $field = $fields->get($key);
            if ($field === null || in_array($field['type'], Templates::SIGNER_FIELDS, true)) {
                continue;
            }
            Guard::noWiringInstructions((string) $value, 'values.'.$key);
            $current[$key] = mb_substr((string) $value, 0, 2000);
        }
        $db->table('form_packet_forms')->where('id', $formId)->update(['values' => json_encode($current)]);
    }

    /**
     * @param  list<array{role: string, name: string, email: string, phone?: string|null, sign_order: int}>  $signers
     */
    public function setSigners(string $packetUlid, array $signers, User $by): void
    {
        [$packet] = $this->packet($packetUlid, $by);
        if ($packet->status !== 'draft') {
            throw ValidationException::withMessages(['packet' => 'Signers can only change before sending.']);
        }
        $db = DB::connection(Connections::cell());
        $db->transaction(function () use ($db, $packet, $signers): void {
            $db->table('form_signers')->where('packet_id', $packet->id)->delete();
            foreach ($signers as $s) {
                if (trim($s['role']) === '' || trim($s['name']) === '' || ! filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['signers' => 'Each signer needs a role, a name and an email address.']);
                }
                $db->table('form_signers')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'packet_id' => $packet->id,
                    'role' => mb_substr(trim($s['role']), 0, 30), 'name' => mb_substr(trim($s['name']), 0, 160), 'email' => mb_strtolower(trim($s['email'])),
                    'phone' => $s['phone'] ?? null, 'sign_order' => max(1, $s['sign_order']), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /**
     * Pre-send check (addition 1): everything that would stop a clean signing.
     *
     * @return list<string>
     */
    public function check(string $packetUlid, User $by): array
    {
        [$packet] = $this->packet($packetUlid, $by);
        $db = DB::connection(Connections::cell());
        $roles = $db->table('form_signers')->where('packet_id', $packet->id)->pluck('role')->all();
        $problems = [];
        if ($roles === []) {
            $problems[] = 'Nobody is set to sign.';
        }
        foreach ($db->table('form_packet_forms')->where('packet_id', $packet->id)->orderBy('sort')->get() as $form) {
            $name = (string) $db->table('form_templates')->where('id', $form->template_id)->value('name');
            $values = (array) json_decode((string) $form->values, true);
            $fields = $this->fieldsFor((int) $form->template_id);
            if (! collect($fields)->contains(fn ($f) => $f['type'] === 'signature')) {
                $problems[] = "{$name}: has no signature field.";
            }
            foreach ($fields as $f) {
                if (in_array($f['type'], Templates::SIGNER_FIELDS, true)) {
                    if (! in_array($f['role'], $roles, true)) {
                        $problems[] = "{$name}: {$f['label']} is for \"{$f['role']}\", but nobody with that role is signing.";
                    }
                } elseif ($f['required'] && trim((string) ($values[$f['key']] ?? '')) === '') {
                    $problems[] = "{$name}: {$f['label']} is required but empty.";
                }
            }
            foreach ($values as $key => $value) {
                try {
                    Guard::noWiringInstructions((string) $value, $key);
                } catch (ValidationException) {
                    $problems[] = "{$name}: {$key} contains bank details; forms never carry wiring instructions.";
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /** The filled PDF of one form in the packet (values only; signatures come with signing). */
    public function preview(string $packetUlid, int $formId, User $by): string
    {
        [$packet] = $this->packet($packetUlid, $by);
        $db = DB::connection(Connections::cell());
        $form = $db->table('form_packet_forms')->where('id', $formId)->where('packet_id', $packet->id)->first()
            ?? throw ValidationException::withMessages(['form' => 'Form not in this packet.']);
        $path = (string) $db->table('form_templates')->where('id', $form->template_id)->value('path');
        $values = (array) json_decode((string) $form->values, true);
        $stamps = [];
        foreach ($this->fieldsFor((int) $form->template_id) as $f) {
            if (in_array($f['type'], Templates::SIGNER_FIELDS, true)) {
                continue;
            }
            $v = (string) ($values[$f['key']] ?? '');
            $stamps[] = ['page' => (int) $f['page'], 'x' => (float) $f['x'], 'y' => (float) $f['y'], 'w' => (float) $f['w'], 'h' => (float) $f['h'],
                'text' => $f['type'] === 'checkbox' ? ($v !== '' && $v !== '0' ? 'X' : '') : $v];
        }

        return Pdf::fill((string) Storage::disk('local')->get($path), $stamps);
    }

    /**
     * Auto-fill (parity): values for bound fields from the deal, its parties, its agent and the brokerage.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public function autofill(Deal $deal, array $fields): array
    {
        $parties = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->orderBy('id')->get();
        $agent = User::query()->find($deal->agent_user_id);
        $money = fn (?int $c): string => $c === null ? '' : '$'.number_format($c / 100, 2);
        $date = fn ($d): string => $d === null ? '' : CarbonImmutable::parse((string) $d)->format('m/d/Y');
        $data = [
            'deal.street' => (string) $deal->street, 'deal.city' => (string) $deal->city, 'deal.state' => (string) $deal->state_code, 'deal.postal_code' => (string) ($deal->postal_code ?? ''),
            'deal.address' => trim("{$deal->street}, {$deal->city}, {$deal->state_code} ".($deal->postal_code ?? '')), 'deal.mls' => (string) ($deal->mls_number ?? ''),
            'deal.price' => $money($deal->price_cents), 'deal.contract_date' => $date($deal->contract_date), 'deal.closing_date' => $date($deal->closing_date),
            'agent.name' => (string) ($agent->name ?? ''), 'agent.email' => (string) ($agent->email ?? ''), 'agent.phone' => (string) ($agent->phone ?? ''),
            'brokerage.name' => (string) $this->tenants->current()->name, 'today' => CarbonImmutable::today()->format('m/d/Y'),
        ];
        foreach (['buyer', 'seller'] as $role) {
            $people = $parties->where('role', $role)->values();
            $data["{$role}.names"] = $people->pluck('name')->implode(' and ');
            foreach ($people as $i => $p) {
                $data["{$role}.".($i + 1).'.name'] = (string) $p->name;
                $data["{$role}.".($i + 1).'.email'] = (string) ($p->email ?? '');
            }
        }
        $values = [];
        foreach ($fields as $f) {
            if (! in_array($f['type'], Templates::SIGNER_FIELDS, true) && ($f['bind'] ?? null) !== null && isset($data[$f['bind']])) {
                $values[$f['key']] = $data[$f['bind']];
            }
        }

        return $values;
    }

    /** @return list<string> the bindings forms can use */
    public static function bindings(): array
    {
        return ['deal.street', 'deal.city', 'deal.state', 'deal.postal_code', 'deal.address', 'deal.mls', 'deal.price', 'deal.contract_date', 'deal.closing_date',
            'agent.name', 'agent.email', 'agent.phone', 'brokerage.name', 'today', 'buyer.names', 'buyer.1.name', 'buyer.1.email', 'buyer.2.name',
            'seller.names', 'seller.1.name', 'seller.1.email', 'seller.2.name'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldsFor(int $templateId): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = array_values((array) json_decode((string) DB::connection(Connections::cell())->table('form_templates')->where('id', $templateId)->value('fields'), true));

        return $fields;
    }

    /**
     * @return list<array{role: string, name: string, email: string, phone: string|null}>
     */
    private function suggestedSigners(Deal $deal): array
    {
        $out = [];
        foreach (DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->whereIn('role', ['buyer', 'seller'])->whereNotNull('email')->orderBy('id')->get() as $p) {
            $n = count(array_filter($out, fn ($s) => str_starts_with($s['role'], (string) $p->role))) + 1;
            $out[] = ['role' => $p->role.$n, 'name' => (string) $p->name, 'email' => (string) $p->email, 'phone' => $p->phone !== null ? (string) $p->phone : null];
        }
        $agent = User::query()->find($deal->agent_user_id);
        if ($agent !== null) {
            $out[] = ['role' => $deal->type === 'buyer' ? 'buyer_agent' : 'listing_agent', 'name' => $agent->name, 'email' => $agent->email, 'phone' => $agent->phone];
        }

        return $out;
    }

    /**
     * @return array{0: \stdClass, 1: Deal}
     */
    public function packet(string $ulid, User $by): array
    {
        $packet = DB::connection(Connections::cell())->table('form_packets')->where('ulid', $ulid)->first();
        if (! $packet instanceof \stdClass) {
            throw ValidationException::withMessages(['packet' => 'Packet not found.']);
        }
        $deal = Deal::query()->findOrFail((int) $packet->deal_id);
        $this->canWork($deal, $by);

        return [$packet, $deal];
    }

    private function canWork(Deal $deal, User $by): void
    {
        $acting = $by->kind === UserKind::Assistant && $by->assisted_user_id !== null ? $by->assisted_user_id : $by->id;
        if (! (Templates::manages($by) || $deal->agent_user_id === $acting)) {
            throw ValidationException::withMessages(['packet' => 'Only the deal\'s agent or the brokerage can prepare its forms.']);
        }
    }
}
