<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/**
 * E-signature (specs/forms/01, part 2): signers sign in order through emailed secure links, after consenting to
 * electronic records (ESIGN/UETA) and, when required, an SMS code. When everyone has signed, one document is
 * assembled with an audit certificate, sealed (SHA-256 + Ed25519) and filed to the deal.
 */
final class Signing
{
    public const ESIGN_DISCLOSURE = 'By continuing you agree to sign electronically and to receive these documents electronically. Your electronic signature has the same effect as a handwritten one (ESIGN Act and UETA). You may ask for a paper copy, and you may withdraw consent before signing by declining.';

    public function __construct(private readonly TenantContext $tenants, private readonly Packets $packets, private readonly Sealer $sealer) {}

    public function send(string $packetUlid, string $verify, User $by, string $baseUrl): void
    {
        $problems = $this->packets->check($packetUlid, $by);
        if ($problems !== []) {
            throw ValidationException::withMessages(['send' => 'Fix these first: '.implode(' ', $problems)]);
        }
        $db = DB::connection(Connections::cell());
        $packet = $db->table('form_packets')->where('ulid', $packetUlid)->first();
        if ($packet === null || $packet->status !== 'draft') {
            throw ValidationException::withMessages(['send' => 'This packet has already been sent.']);
        }
        if ($verify === 'sms' && $db->table('form_signers')->where('packet_id', $packet->id)->whereNull('phone')->exists()) {
            throw ValidationException::withMessages(['send' => 'Text-code verification needs a mobile number for every signer.']);
        }
        $db->table('form_packets')->where('id', $packet->id)->update(['status' => 'sent', 'verify' => $verify === 'sms' ? 'sms' : 'email', 'sent_at' => now(), 'updated_at' => now()]);
        $this->event((int) $packet->id, null, 'sent', 'Sent for signature by '.$by->name);
        app(Identity\IdentityChecks::class)->prepare((int) $packet->id);
        $this->notifyNext((int) $packet->id, $baseUrl);
        app(AuditLogger::class)->record('forms.packet.sent', metadata: ['packet' => $packetUlid, 'verify' => $verify], severity: 'notice');
    }

    /** Emails the next signers in order (everyone with the lowest order not yet signed). */
    public function notifyNext(int $packetId, string $baseUrl): void
    {
        $db = DB::connection(Connections::cell());
        $next = $db->table('form_signers')->where('packet_id', $packetId)->whereNull('signed_at')->min('sign_order');
        if ($next === null) {
            return;
        }
        $packet = $db->table('form_packets')->where('id', $packetId)->first();
        if (! $packet instanceof \stdClass) {
            return;
        }
        $agent = User::query()->find((int) $db->table('tx_deals')->where('id', $packet->deal_id)->value('agent_user_id'));
        foreach ($db->table('form_signers')->where('packet_id', $packetId)->where('sign_order', $next)->where('status', 'waiting')->get() as $s) {
            $token = Str::random(48);
            $db->table('form_signers')->where('id', $s->id)->update(['token_hash' => hash('sha256', $token), 'status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            Notification::route('mail', (string) $s->email)->notify(new SignatureRequested((string) $packet->title, (string) $this->tenants->current()->name,
                $agent->name ?? 'Your agent', $baseUrl.'/sign/'.$token, false));
            $this->event($packetId, (int) $s->id, 'emailed', 'Signing link emailed to '.$s->email);
        }
    }

    /**
     * @return array{signer: \stdClass, packet: \stdClass}
     */
    public function byToken(string $token, bool $allowFinished = false): array
    {
        $db = DB::connection(Connections::cell());
        $signer = $db->table('form_signers')->where('token_hash', hash('sha256', $token))->first();
        $packet = $signer !== null ? $db->table('form_packets')->where('id', $signer->packet_id)->first() : null;
        if (! $signer instanceof \stdClass || ! $packet instanceof \stdClass || (! $allowFinished && $packet->status !== 'sent')) {
            abort(404);
        }

        return ['signer' => $signer, 'packet' => $packet];
    }

    public function viewed(string $token, ?string $ip): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if ($s->viewed_at === null) {
            DB::connection(Connections::cell())->table('form_signers')->where('id', $s->id)->update(['viewed_at' => now(), 'status' => $s->status === 'sent' ? 'viewed' : $s->status]);
            $this->event((int) $p->id, (int) $s->id, 'viewed', null, $ip);
        }
    }

    public function consent(string $token, ?string $ip): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if ($s->consent_at === null) {
            DB::connection(Connections::cell())->table('form_signers')->where('id', $s->id)->update(['consent_at' => now()]);
            $this->event((int) $p->id, (int) $s->id, 'consented', 'Agreed to sign electronically (ESIGN/UETA disclosure shown)', $ip);
        }
    }

    public function sendCode(string $token): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if ($p->verify !== 'sms' || $s->phone === null) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        DB::connection(Connections::cell())->table('form_signers')->where('id', $s->id)->update(['sms_code_hash' => hash('sha256', $code), 'sms_attempts' => 0]);
        app(SmsSender::class)->send((string) $s->phone, "{$code} is your code to sign documents from {$this->tenants->current()->name}. It expires when used.");
        $this->event((int) $p->id, (int) $s->id, 'code_sent', 'Code texted to number ending '.substr((string) $s->phone, -4));
    }

    public function verifyCode(string $token, string $code, ?string $ip): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if ((int) $s->sms_attempts >= 5 || $s->sms_code_hash === null) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Ask for a new code.']);
        }
        DB::connection(Connections::cell())->table('form_signers')->where('id', $s->id)->increment('sms_attempts');
        if (! hash_equals((string) $s->sms_code_hash, hash('sha256', trim($code)))) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }
        DB::connection(Connections::cell())->table('form_signers')->where('id', $s->id)->update(['verified_at' => now(), 'sms_code_hash' => null]);
        $this->event((int) $p->id, (int) $s->id, 'verified', 'Identity checked by text code', $ip);
    }

    /** Signs every field for this signer's role; a drawn signature is a PNG data URL, otherwise the typed name. */
    public function sign(string $token, ?string $typed, ?string $drawnDataUrl, string $initials, ?string $ip, ?string $userAgent, string $baseUrl): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if ($s->signed_at !== null) {
            return;
        }
        if ($s->consent_at === null) {
            throw ValidationException::withMessages(['sign' => 'Agree to sign electronically first.']);
        }
        if ($p->verify === 'sms' && $s->verified_at === null) {
            throw ValidationException::withMessages(['sign' => 'Enter the code we texted you first.']);
        }
        if (($blocker = Identity\IdentityChecks::blocker($s)) !== null) {
            throw ValidationException::withMessages(['sign' => $blocker]);
        }
        $image = null;
        if ($drawnDataUrl !== null && $drawnDataUrl !== '') {
            if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $drawnDataUrl, $m) !== 1 || strlen($m[1]) > 300_000
                || ! str_starts_with((string) base64_decode($m[1], true), "\x89PNG")) {
                throw ValidationException::withMessages(['signature' => 'Draw your signature again.']);
            }
            $image = 'forms/'.$this->tenants->id().'/signatures/'.Str::ulid().'.png';
            Storage::disk('local')->put($image, (string) base64_decode($m[1], true));
        } elseif (mb_strtolower(trim((string) $typed)) !== mb_strtolower(trim((string) $s->name))) {
            throw ValidationException::withMessages(['signature' => 'Type your full name exactly as '.$s->name.', or draw your signature.']);
        }
        if (preg_match('/^[A-Za-z]{1,5}$/', trim($initials)) !== 1) {
            throw ValidationException::withMessages(['initials' => 'Type your initials (letters only).']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('form_signers')->where('id', $s->id)->update(['signed_at' => now(), 'status' => 'signed', 'signature_text' => trim((string) ($typed ?? $s->name)),
            'signature_image' => $image, 'initials' => strtoupper(trim($initials)), 'ip' => $ip, 'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'updated_at' => now()]);
        $this->event((int) $p->id, (int) $s->id, 'signed', ($image !== null ? 'Drawn signature' : 'Typed signature').($s->hosted_by !== null ? ', in person' : ''), $ip);

        if (! $this->completeIfDone((int) $p->id, $baseUrl) && $db->table('form_signers')->where('packet_id', $p->id)->whereNull('signed_at')->exists()
            && ! $db->table('form_signers')->where('packet_id', $p->id)->where('sign_order', $s->sign_order)->whereNull('signed_at')->exists()) {
            $this->notifyNext((int) $p->id, $baseUrl);
        }
    }

    /** Completes the packet once everyone has signed and every change made after sending has been initialled. */
    public function completeIfDone(int $packetId, string $baseUrl): bool
    {
        $db = DB::connection(Connections::cell());
        if ($db->table('form_packets')->where('id', $packetId)->value('status') !== 'sent'
            || $db->table('form_signers')->where('packet_id', $packetId)->whereNull('signed_at')->exists() || Negotiations::pendingInPacket($packetId)) {
            return false;
        }
        $this->complete($packetId, $baseUrl);

        return true;
    }

    public function decline(string $token, string $reason, ?string $ip): void
    {
        ['signer' => $s, 'packet' => $p] = $this->byToken($token);
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why, so your agent can help.']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('form_signers')->where('id', $s->id)->update(['status' => 'declined', 'decline_reason' => mb_substr(trim($reason), 0, 300), 'updated_at' => now()]);
        $db->table('form_packets')->where('id', $p->id)->update(['status' => 'declined', 'updated_at' => now()]);
        $this->event((int) $p->id, (int) $s->id, 'declined', $reason, $ip);
        User::query()->find((int) $db->table('tx_deals')->where('id', $p->deal_id)->value('agent_user_id'))
            ?->notify(new SignerDeclined((string) $s->name, (string) $p->title, trim($reason), (string) $p->ulid));
    }

    public function void(string $packetUlid, string $reason, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('ulid', $packetUlid)->whereIn('status', ['sent', 'declined'])->first();
        if ($p === null) {
            throw ValidationException::withMessages(['void' => 'Only a packet out for signature can be voided.']);
        }
        $this->packets->check($packetUlid, $by); // access check
        $db->table('form_packets')->where('id', $p->id)->update(['status' => 'voided', 'void_reason' => mb_substr(trim($reason), 0, 200), 'updated_at' => now()]);
        $db->table('form_signers')->where('packet_id', $p->id)->update(['token_hash' => null]);
        $this->event((int) $p->id, null, 'voided', $reason.' — by '.$by->name);
    }

    /** In-person signing on the agent's device: a fresh link for that signer. */
    public function inPerson(string $packetUlid, string $signerUlid, User $by, string $baseUrl): string
    {
        $this->packets->check($packetUlid, $by); // access check
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('ulid', $packetUlid)->where('status', 'sent')->first();
        $s = $p !== null ? $db->table('form_signers')->where('ulid', $signerUlid)->where('packet_id', $p->id)->whereNull('signed_at')->first() : null;
        if ($p === null || $s === null) {
            throw ValidationException::withMessages(['in_person' => 'That signer has already signed or is not part of this packet.']);
        }
        $next = (int) $db->table('form_signers')->where('packet_id', $p->id)->whereNull('signed_at')->min('sign_order');
        if ((int) $s->sign_order !== $next) {
            throw ValidationException::withMessages(['in_person' => 'Others must sign before this person.']);
        }
        $token = Str::random(48);
        $db->table('form_signers')->where('id', $s->id)->update(['token_hash' => hash('sha256', $token), 'status' => 'sent', 'hosted_by' => $by->id, 'sent_at' => $s->sent_at ?? now()]);
        $this->event((int) $p->id, (int) $s->id, 'in_person', 'Signing in person on '.$by->name.'\'s device');

        return $baseUrl.'/sign/'.$token;
    }

    /** Daily: a reminder every 2 days, up to 3, to signers who have not signed. */
    public function remind(string $baseUrl): int
    {
        $db = DB::connection(Connections::cell());
        $sent = 0;
        foreach ($db->table('form_signers')->join('form_packets', 'form_packets.id', '=', 'form_signers.packet_id')->where('form_packets.status', 'sent')
            ->whereIn('form_signers.status', ['sent', 'viewed'])->where('form_signers.reminders', '<', 3)->where('form_signers.sent_at', '<=', now()->subDays(2))
            ->where(fn ($q) => $q->whereNull('form_signers.reminded_at')->orWhere('form_signers.reminded_at', '<=', now()->subDays(2)))
            ->get(['form_signers.*', 'form_packets.title', 'form_packets.deal_id']) as $s) {
            $token = Str::random(48);
            $db->table('form_signers')->where('id', $s->id)->update(['token_hash' => hash('sha256', $token), 'reminders' => (int) $s->reminders + 1, 'reminded_at' => now()]);
            $agent = User::query()->find((int) $db->table('tx_deals')->where('id', $s->deal_id)->value('agent_user_id'));
            Notification::route('mail', (string) $s->email)->notify(new SignatureRequested((string) $s->title, (string) $this->tenants->current()->name, $agent->name ?? 'Your agent', $baseUrl.'/sign/'.$token, true));
            $this->event((int) $s->packet_id, (int) $s->id, 'reminded', null);
            $sent++;
        }

        return $sent;
    }

    /** Assembles, seals and files the completed packet, and sends everyone their copy. */
    public function complete(int $packetId, string $baseUrl): void
    {
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('id', $packetId)->first();
        if (! $p instanceof \stdClass) {
            return;
        }
        $signers = $db->table('form_signers')->where('packet_id', $packetId)->orderBy('sign_order')->get()->keyBy('role');
        $forms = [];
        $names = [];
        foreach ($db->table('form_packet_forms')->where('packet_id', $packetId)->orderBy('sort')->get() as $f) {
            $t = $db->table('form_templates')->where('id', $f->template_id)->first();
            if (! $t instanceof \stdClass) {
                continue;
            }
            $names[] = (string) $t->name;
            $values = (array) json_decode((string) $f->values, true);
            $stamps = [];
            foreach ((array) json_decode((string) $t->fields, true) as $field) {
                $base = ['page' => (int) $field['page'], 'x' => (float) $field['x'], 'y' => (float) $field['y'], 'w' => (float) $field['w'], 'h' => (float) $field['h']];
                $s = $field['role'] !== null ? $signers->get($field['role']) : null;
                $stamps[] = $base + match ($field['type']) {
                    'signature' => $s?->signature_image !== null ? ['image' => (string) Storage::disk('local')->get((string) $s->signature_image)] : ['text' => (string) ($s->signature_text ?? ''), 'style' => 'signature'],
                    'initials' => ['text' => (string) ($s->initials ?? '')],
                    'date_signed' => ['text' => $s?->signed_at !== null ? CarbonImmutable::parse((string) $s->signed_at)->format('m/d/Y') : ''],
                    'checkbox' => ['text' => ($values[$field['key']] ?? '') !== '' && ($values[$field['key']] ?? '') !== '0' ? 'X' : ''],
                    default => ['text' => (string) ($values[$field['key']] ?? '')],
                };
            }
            $forms[] = ['pdf' => (string) Storage::disk('local')->get((string) $t->path), 'stamps' => $stamps];
        }
        $certificate = ['Document: '.$p->title.' ('.implode(', ', $names).')', 'Brokerage: '.$this->tenants->current()->name, 'Envelope: '.$p->ulid,
            'Sent: '.$p->sent_at.' UTC · Verification: '.($p->verify === 'sms' ? 'email link and text code' : 'email link'), ''];
        foreach ($signers as $s) {
            $id = $s->identity_check_id !== null && $s->id_status === 'verified' ? $db->table('form_identity_checks')->where('id', $s->identity_check_id)->first() : null;
            $certificate[] = sprintf('%s (%s, %s): consented %s, signed %s UTC from %s%s%s%s', $s->name, $s->role, $s->email, $s->consent_at, $s->signed_at, $s->ip ?? 'unknown',
                $s->verified_at !== null ? ', text code verified' : '', $s->hosted_by !== null ? ', in person' : '',
                $id !== null ? ', identity verified '.substr((string) $id->checked_at, 0, 10).' ('.Identity\IdentityChecks::documentLabel($id->document_type, $id->document_country).', '.$id->provider.')' : '');
        }
        $owner = json_decode((string) ($p->owner_record ?? 'null'), true);
        if (is_array($owner)) {
            $certificate[] = 'Owner of record ('.$owner['source'].'): '.implode(', ', (array) $owner['owners']).($p->owner_override_reason !== null ? ' · mismatch accepted by the brokerage: '.$p->owner_override_reason : '');
        }
        $changes = $db->table('form_changes')->where('packet_id', $packetId)->orderBy('id')->get();
        if ($changes->isNotEmpty()) {
            $certificate[] = '';
            $certificate[] = 'Changes made after sending, and who initialled them:';
            $signerNames = $db->table('form_signers')->where('packet_id', $packetId)->pluck('name', 'id');
            foreach ($changes as $c) {
                $acks = $db->table('form_change_acks')->where('change_id', $c->id)->get()
                    ->map(fn ($a): string => $signerNames[$a->signer_id].' ('.$a->initials.', '.$a->at.' UTC'.($a->ip !== null ? ', '.$a->ip : '').')')->implode('; ');
                $certificate[] = $c->changed_at.' UTC · '.Negotiations::line((string) $c->label, (string) $c->old_value, (string) $c->new_value).' · '.$c->reason
                    .' · initialled by: '.($acks !== '' ? $acks : 'no earlier signers');
            }
        }
        $certificate[] = '';
        foreach ($db->table('form_events')->where('packet_id', $packetId)->orderBy('id')->get() as $e) {
            $certificate[] = "{$e->at} UTC · {$e->action}".($e->detail !== null ? " · {$e->detail}" : '').($e->ip !== null ? " · {$e->ip}" : '');
        }
        $certificate[] = '';
        $certificate[] = 'This document is sealed: its SHA-256 fingerprint is signed with RightAlly\'s Ed25519 key ('.$this->sealer->publicKeyBase64().'). Check any copy at '.$baseUrl.'/verify-document.';
        $pdf = Pdf::assemble($forms, $certificate);
        $sha = hash('sha256', $pdf);
        $path = 'forms/'.$this->tenants->id().'/packets/'.$p->ulid.'-signed.pdf';
        Storage::disk('local')->put($path, $pdf);
        $db->table('form_packets')->where('id', $packetId)->update(['status' => 'completed', 'completed_at' => now(), 'sealed_path' => $path, 'sealed_sha256' => $sha,
            'seal_signature' => $this->sealer->sign($sha), 'updated_at' => now()]);
        $this->event($packetId, null, 'completed', 'All signed; sealed with SHA-256 '.$sha);
        $this->file($p, $pdf);
        foreach ($signers as $s) {
            Notification::route('mail', (string) $s->email)->notify(new PacketCompleted((string) $p->title, (string) $this->tenants->current()->name, $pdf));
        }
        app(AuditLogger::class)->record('forms.packet.completed', metadata: ['packet' => $p->ulid, 'sha256' => $sha], severity: 'notice');
    }

    /**
     * Checks a copy against the sealed record.
     *
     * @return array{valid: bool, title?: string, completed_at?: string, signers?: list<string>}
     */
    public function verifyDocument(string $contents): array
    {
        $sha = hash('sha256', $contents);
        $p = DB::connection(Connections::cell())->table('form_packets')->where('sealed_sha256', $sha)->first();
        if ($p === null || ! $this->sealer->valid($sha, (string) $p->seal_signature)) {
            return ['valid' => false];
        }

        /** @var list<string> $signers */
        $signers = array_values(array_map('strval', DB::connection(Connections::cell())->table('form_signers')->where('packet_id', $p->id)->orderBy('sign_order')->pluck('name')->all()));

        return ['valid' => true, 'title' => (string) $p->title, 'completed_at' => (string) $p->completed_at, 'signers' => $signers];
    }

    private function file(\stdClass $p, string $pdf): void
    {
        $deal = Deal::query()->find((int) $p->deal_id);
        $by = User::query()->find((int) $p->created_by);
        if ($deal === null || $by === null) {
            return;
        }
        $labels = DB::connection(Connections::cell())->table('form_packet_forms')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')
            ->where('form_packet_forms.packet_id', $p->id)->whereNotNull('form_templates.checklist_label')->pluck('form_templates.checklist_label')->unique()->values();
        $items = $labels->map(fn ($l) => Item::query()->where('deal_id', $deal->id)->where('label', $l)->first())->filter()->values();
        $tmp = tempnam(sys_get_temp_dir(), 'signed-');
        foreach ($items->isEmpty() ? [null] : $items->all() as $item) {
            file_put_contents($tmp, $pdf);
            app(Documents::class)->upload($deal, $item, new UploadedFile($tmp, 'Signed - '.$p->title.'.pdf', 'application/pdf', null, true), 'esign', $by);
        }
        @unlink($tmp);
    }

    public function recordEvent(int $packetId, ?int $signerId, string $action, ?string $detail, ?string $ip = null): void
    {
        $this->event($packetId, $signerId, $action, $detail, $ip);
    }

    private function event(int $packetId, ?int $signerId, string $action, ?string $detail, ?string $ip = null): void
    {
        DB::connection(Connections::cell())->table('form_events')->insert(['tenant_id' => $this->tenants->id(), 'packet_id' => $packetId, 'signer_id' => $signerId,
            'action' => $action, 'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null, 'ip' => $ip, 'at' => now()]);
    }
}
