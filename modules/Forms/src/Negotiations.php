<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Forms part 4 (specs/forms/01, additions 2 and 6):
 *  - Amend: change terms on a packet already out for signature. Signers who already signed initial only the
 *    changes (with a fresh link); everyone else simply signs the current terms. The packet completes when all
 *    have signed and every change is initialled, and the certificate lists each change and who initialled it.
 *  - Counter: a new draft packet generated from the original with the changed terms and a plain summary
 *    ("Price: 485,000 → 470,000"), linked in one negotiation history; an open original is voided.
 *  - Addendum: a new packet of chosen forms attached to a completed one, with the same signers.
 */
final class Negotiations
{
    public function __construct(private readonly TenantContext $tenants, private readonly Packets $packets, private readonly Signing $signing) {}

    /**
     * @param  array<string, string>  $values  field key => new value
     * @return int number of fields changed
     */
    public function amend(string $packetUlid, int $formId, array $values, string $reason, User $by, string $baseUrl): int
    {
        [$packet] = $this->packets->packet($packetUlid, $by);
        if ($packet->status !== 'sent') {
            throw ValidationException::withMessages(['amend' => 'Only a packet out for signature can be changed this way. Before sending, just edit it.']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why the terms changed; signers see this.']);
        }
        $db = DB::connection(Connections::cell());
        $form = $db->table('form_packet_forms')->where('id', $formId)->where('packet_id', $packet->id)->first()
            ?? throw ValidationException::withMessages(['form' => 'Form not in this packet.']);
        $current = (array) json_decode((string) $form->values, true);
        $changes = $this->diff($this->fields((int) $form->template_id), $current, $values);
        if ($changes === []) {
            throw ValidationException::withMessages(['amend' => 'Nothing changed.']);
        }

        $signed = $db->table('form_signers')->where('packet_id', $packet->id)->whereNotNull('signed_at')->get();
        $db->transaction(function () use ($db, $packet, $form, $current, $changes, $reason, $by, $signed): void {
            foreach ($changes as $c) {
                $current[$c['key']] = $c['new'];
                $id = $db->table('form_changes')->insertGetId(['tenant_id' => $this->tenants->id(), 'packet_id' => $packet->id, 'form_id' => $form->id, 'field_key' => $c['key'],
                    'label' => $c['label'], 'old_value' => $c['old'], 'new_value' => $c['new'], 'reason' => mb_substr(trim($reason), 0, 300), 'changed_by' => $by->id, 'changed_at' => now()]);
                foreach ($signed as $s) {
                    $db->table('form_change_acks')->insert(['tenant_id' => $this->tenants->id(), 'change_id' => $id, 'signer_id' => $s->id]);
                }
            }
            $db->table('form_packet_forms')->where('id', $form->id)->update(['values' => json_encode($current)]);
        });
        $lines = array_map(fn (array $c): string => self::line($c['label'], $c['old'], $c['new']), $changes);
        $this->signing->recordEvent((int) $packet->id, null, 'amended', implode('; ', $lines).' — '.trim($reason).' — by '.$by->name);

        $agent = User::query()->find((int) $db->table('tx_deals')->where('id', $packet->deal_id)->value('agent_user_id'));
        foreach ($signed as $s) {
            $token = Str::random(48);
            $db->table('form_signers')->where('id', $s->id)->update(['token_hash' => hash('sha256', $token), 'updated_at' => now()]);
            Notification::route('mail', (string) $s->email)->notify(new ChangesToInitial((string) $packet->title, (string) $this->tenants->current()->name,
                $agent->name ?? 'Your agent', $lines, trim($reason), $baseUrl.'/sign/'.$token));
            $this->signing->recordEvent((int) $packet->id, (int) $s->id, 'emailed', 'Changes to initial emailed to '.$s->email);
        }
        app(AuditLogger::class)->record('forms.packet.amended', metadata: ['packet' => $packetUlid, 'fields' => count($changes)], severity: 'notice');

        return count($changes);
    }

    /**
     * Changes this signer still has to initial.
     *
     * @return list<array{id: int, form: string, label: string, old: string, new: string, reason: string}>
     */
    public static function pendingFor(int $signerId): array
    {
        $db = DB::connection(Connections::cell());

        return array_values($db->table('form_change_acks')->join('form_changes', 'form_changes.id', '=', 'form_change_acks.change_id')
            ->join('form_packet_forms', 'form_packet_forms.id', '=', 'form_changes.form_id')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')
            ->where('form_change_acks.signer_id', $signerId)->whereNull('form_change_acks.at')->orderBy('form_changes.id')
            ->get(['form_changes.id', 'form_templates.name', 'form_changes.label', 'form_changes.old_value', 'form_changes.new_value', 'form_changes.reason'])
            ->map(fn ($c): array => ['id' => (int) $c->id, 'form' => (string) $c->name, 'label' => (string) $c->label, 'old' => (string) $c->old_value,
                'new' => (string) $c->new_value, 'reason' => (string) $c->reason])->all());
    }

    public static function pendingInPacket(int $packetId): bool
    {
        return DB::connection(Connections::cell())->table('form_change_acks')->join('form_changes', 'form_changes.id', '=', 'form_change_acks.change_id')
            ->where('form_changes.packet_id', $packetId)->whereNull('form_change_acks.at')->exists();
    }

    /** A signer who already signed initials every pending change; the packet completes once nothing is outstanding. */
    public function acknowledge(string $token, string $initials, ?string $ip, string $baseUrl): void
    {
        ['signer' => $s, 'packet' => $p] = $this->signing->byToken($token);
        $pending = self::pendingFor((int) $s->id);
        if ($s->signed_at === null || $pending === []) {
            return;
        }
        if (preg_match('/^[A-Za-z]{1,5}$/', trim($initials)) !== 1) {
            throw ValidationException::withMessages(['initials' => 'Type your initials (letters only).']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('form_change_acks')->where('signer_id', $s->id)->whereIn('change_id', array_column($pending, 'id'))
            ->update(['initials' => strtoupper(trim($initials)), 'ip' => $ip, 'at' => now()]);
        $this->signing->recordEvent((int) $p->id, (int) $s->id, 'initialed', 'Initialled '.count($pending).' change(s): '.implode(', ', array_column($pending, 'label')), $ip);
        $this->signing->completeIfDone((int) $p->id, $baseUrl);
    }

    /**
     * A counter-offer: a new draft with the changed terms, linked to the original; an open original is voided.
     *
     * @param  array<int|string, array<string, string>>  $changes  form id in the original => [field key => new value]
     */
    public function counter(string $packetUlid, array $changes, User $by): string
    {
        [$source, $deal] = $this->packets->packet($packetUlid, $by);
        if (! in_array($source->status, ['sent', 'declined', 'completed'], true)) {
            throw ValidationException::withMessages(['counter' => 'Counter a packet that has been sent, declined or signed.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('form_packets')->where('parent_packet_id', $source->id)->whereIn('status', ['draft', 'sent'])->exists()) {
            throw ValidationException::withMessages(['counter' => 'There is already an open counter or addendum for this packet.']);
        }
        $root = (string) ($source->negotiation_ulid ?? $source->ulid);
        $round = (int) $source->round + 1;
        $summary = [];
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $source, $deal, $changes, $by, $root, $round, $ulid, &$summary): void {
            $db->table('form_packets')->where('id', $source->id)->update(['negotiation_ulid' => $root]);
            $id = $db->table('form_packets')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id,
                'title' => mb_substr(self::baseTitle((string) $source->title).' — counter '.($round - 1), 0, 160), 'status' => 'draft', 'created_by' => $by->id,
                'parent_packet_id' => $source->id, 'negotiation_ulid' => $root, 'round' => $round, 'kind' => 'counter', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($db->table('form_packet_forms')->where('packet_id', $source->id)->orderBy('sort')->get() as $f) {
                $values = (array) json_decode((string) $f->values, true);
                $diff = $this->diff($this->fields((int) $f->template_id), $values, (array) ($changes[$f->id] ?? $changes[(string) $f->id] ?? []));
                $name = (string) $db->table('form_templates')->where('id', $f->template_id)->value('name');
                foreach ($diff as $c) {
                    $values[$c['key']] = $c['new'];
                    $summary[] = ['form' => $name, 'label' => $c['label'], 'old' => $c['old'], 'new' => $c['new']];
                }
                $db->table('form_packet_forms')->insert(['tenant_id' => $this->tenants->id(), 'packet_id' => $id, 'template_id' => $f->template_id,
                    'template_version' => $f->template_version, 'values' => json_encode($values), 'sort' => $f->sort]);
            }
            if ($summary === []) {
                throw ValidationException::withMessages(['counter' => 'Change at least one term for a counter-offer.']);
            }
            $db->table('form_packets')->where('id', $id)->update(['change_summary' => json_encode($summary)]);
            $this->copySigners((int) $source->id, $id);
        });
        if (in_array($source->status, ['sent', 'declined'], true)) {
            $this->signing->void((string) $source->ulid, 'Countered in round '.$round, $by);
        }
        $this->signing->recordEvent((int) $source->id, null, 'countered', count($summary).' term(s) changed in round '.$round.' — by '.$by->name);
        app(AuditLogger::class)->record('forms.packet.countered', $deal, metadata: ['packet' => $ulid, 'from' => $source->ulid, 'round' => $round], label: $deal->street);

        return $ulid;
    }

    /**
     * An addendum: chosen forms attached to a completed packet, signed by the same people.
     *
     * @param  list<string>  $templateUlids
     */
    public function addendum(string $packetUlid, array $templateUlids, User $by): string
    {
        [$source, $deal] = $this->packets->packet($packetUlid, $by);
        if ($source->status !== 'completed') {
            throw ValidationException::withMessages(['addendum' => 'Add an addendum to a fully signed packet.']);
        }
        $ulid = $this->packets->create($deal, 'Addendum to '.self::baseTitle((string) $source->title), $templateUlids, $by);
        $db = DB::connection(Connections::cell());
        $root = (string) ($source->negotiation_ulid ?? $source->ulid);
        $db->table('form_packets')->where('id', $source->id)->update(['negotiation_ulid' => $root]);
        $db->table('form_packets')->where('ulid', $ulid)->update(['kind' => 'addendum', 'parent_packet_id' => $source->id, 'negotiation_ulid' => $root, 'round' => $source->round]);
        $id = (int) $db->table('form_packets')->where('ulid', $ulid)->value('id');
        $db->table('form_signers')->where('packet_id', $id)->delete();
        $this->copySigners((int) $source->id, $id);
        $this->signing->recordEvent((int) $source->id, null, 'addendum', 'Addendum started by '.$by->name);

        return $ulid;
    }

    /**
     * Every packet in the same negotiation, in order.
     *
     * @return list<array{ulid: string, title: string, kind: string, round: int, status: string, changes: list<string>}>
     */
    public static function history(\stdClass $packet): array
    {
        $root = $packet->negotiation_ulid ?? null;
        if ($root === null) {
            return [];
        }

        return array_values(DB::connection(Connections::cell())->table('form_packets')->where('negotiation_ulid', $root)->orderBy('round')->orderBy('id')->get()
            ->map(fn ($p): array => ['ulid' => (string) $p->ulid, 'title' => (string) $p->title, 'kind' => (string) $p->kind, 'round' => (int) $p->round, 'status' => (string) $p->status,
                'changes' => array_values(array_map(fn (array $c): string => self::line((string) $c['label'], (string) $c['old'], (string) $c['new']), (array) json_decode((string) ($p->change_summary ?? '[]'), true)))])
            ->all());
    }

    /**
     * "Label: old → new", with the exact difference in plain words for money and dates
     * ("Purchase price: $485,000 → $470,000 (down $15,000)"; "Closing date: 07/01/2026 → 07/11/2026 (10 days later)").
     */
    public static function line(string $label, string $old, string $new): string
    {
        $show = fn (string $v): string => $v === '' ? '(blank)' : (mb_strlen($v) > 80 ? mb_substr($v, 0, 77).'…' : $v);

        return "{$label}: {$show($old)} → {$show($new)}".self::difference($label, $old, $new);
    }

    private static function difference(string $label, string $old, string $new): string
    {
        $from = Summaries::date($old);
        $to = Summaries::date($new);
        if ($from !== null && $to !== null && $from !== $to) {
            $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);

            return ' ('.abs($days).' day'.(abs($days) === 1 ? '' : 's').($days > 0 ? ' later' : ' earlier').')';
        }
        $money = fn (string $v): ?float => preg_match('/^\$?\s*\d{1,3}(,\d{3})*(\.\d{1,2})?$|^\$?\s*\d+(\.\d{1,2})?$/', trim($v)) === 1 ? (float) str_replace(['$', ',', ' '], '', $v) : null;
        $a = $money($old);
        $b = $money($new);
        $isMoney = str_contains($old.$new, '$') || preg_match('/price|amount|deposit|fee|credit|commission|money|cost/i', $label) === 1;
        if ($a !== null && $b !== null && $a !== $b && $isMoney) {
            $diff = $b - $a;
            $amount = '$'.number_format(abs($diff), fmod(abs($diff), 1.0) === 0.0 ? 0 : 2);

            return ' ('.($diff > 0 ? 'up ' : 'down ').$amount.')';
        }

        return '';
    }

    private static function baseTitle(string $title): string
    {
        return (string) preg_replace('/ — counter \d+$/u', '', $title);
    }

    private function copySigners(int $from, int $to): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($db->table('form_signers')->where('packet_id', $from)->orderBy('sign_order')->get() as $s) {
            $db->table('form_signers')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'packet_id' => $to, 'role' => $s->role, 'name' => $s->name,
                'email' => $s->email, 'phone' => $s->phone, 'sign_order' => $s->sign_order, 'met_in_person' => $s->met_in_person, 'status' => 'waiting',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return list<array{key: string, label: string, old: string, new: string}>
     */
    private function diff(array $fields, array $current, array $values): array
    {
        $byKey = collect($fields)->keyBy('key');
        $out = [];
        foreach ($values as $key => $value) {
            $field = $byKey->get((string) $key);
            if ($field === null || in_array($field['type'], Templates::SIGNER_FIELDS, true)) {
                continue;
            }
            Guard::noWiringInstructions((string) $value, 'values.'.$key);
            $new = mb_substr((string) $value, 0, 2000);
            $old = (string) ($current[$key] ?? '');
            if ($new !== $old) {
                $out[] = ['key' => (string) $key, 'label' => (string) ($field['label'] ?? $key), 'old' => $old, 'new' => $new];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(int $templateId): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = array_values((array) json_decode((string) DB::connection(Connections::cell())->table('form_templates')->where('id', $templateId)->value('fields'), true));

        return $fields;
    }
}
