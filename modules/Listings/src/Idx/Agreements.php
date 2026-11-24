<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Watchtower\Settings as Watchtower;
use Modules\Forms\Events\PacketSealed;
use Modules\Forms\Signing;
use Modules\Forms\Templates;
use Modules\Listings\Idx\Mail\IdxAgreementMail;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Jobs\CheckIdxAgreement;
use Modules\Listings\Models\MlsSource;
use setasign\Fpdi\Fpdi;
use stdClass;
use Throwable;

/**
 * IDX6: the IDX agreement for each brokerage and board.
 *
 * 1. When the brokerage sends its MLS details, the board's agreement is prepared: the MLS's own PDF (set by HQ) with a
 *    signature page added, filled with the brokerage's details. It goes out through RightAlly's e-signature: the
 *    brokerage first, then the managing broker.
 * 2. When everyone has signed, RightAlly keeps a copy, checks the brokerage's website loads over HTTPS (MLS reviewers
 *    reject blank or locked sites), and emails the signed agreement to the board's "send to" addresses with its CC and
 *    BCC. The board then shows "Sent to the MLS".
 * 3. Every night, each live board's website is checked: it loads, and its home search shows the MLS notice.
 */
final class Agreements
{
    /** Letter size, in points. */
    private const PAGE = [612.0, 792.0];

    public function __construct(private readonly TenantContext $tenants, private readonly AuditLogger $audit, private readonly Http $http) {}

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }

    /** Whether HQ has set where this board's signed agreement goes (otherwise HQ handles the paperwork itself). */
    public static function automatic(stdClass $board): bool
    {
        return self::emails((string) ($board->send_to ?? '')) !== [];
    }

    /**
     * Prepares the agreement and sends it for signing. Returns false when the board is not set up for it.
     */
    public function prepare(stdClass $sub): bool
    {
        $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->first();
        $tenant = Tenant::query()->whereKey((int) $sub->tenant_id)->first();
        if (! $board instanceof stdClass || $tenant === null || ! self::automatic($board)) {
            return false;
        }

        return (bool) $this->tenants->runAs($tenant, function () use ($sub, $board, $tenant): bool {
            $owner = User::query()->where('kind', UserKind::Owner)->orderBy('id')->first();
            if (! $owner instanceof User) {
                return false;
            }
            $website = $this->website((int) $tenant->id);
            $signers = [['role' => 'brokerage', 'name' => $owner->name, 'email' => mb_strtolower((string) $owner->email), 'label' => 'Brokerage ('.$tenant->name.')']];
            $broker = mb_strtolower((string) $sub->managing_broker_email);
            if ((bool) $board->managing_broker_signs && $broker !== '' && $broker !== $signers[0]['email']) {
                $signers[] = ['role' => 'managing_broker', 'name' => (string) $sub->managing_broker_name, 'email' => $broker, 'label' => 'Managing broker'];
            }
            [$pdf, $fields] = $this->document($board, $sub, $tenant, $website, $signers);
            $templates = app(Templates::class);
            $templateUlid = $templates->fromPdf('IDX agreement: '.$board->code, $pdf, $fields, $owner, ['source' => 'association', 'import_source' => 'idx']);
            $template = $templates->find($templateUlid);
            if ($template === null) {
                return false;
            }
            $cell = DB::connection(Connections::cell());
            $packetUlid = (string) Str::ulid();
            $packetId = (int) $cell->table('form_packets')->insertGetId(['ulid' => $packetUlid, 'tenant_id' => $tenant->id, 'deal_id' => 0,
                'title' => mb_substr('IDX agreement: '.$board->name, 0, 160), 'status' => 'sent', 'verify' => 'email', 'sent_at' => now(), 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now()]);
            // IDX12: the board's own form carries the values the brokerage reviewed.
            $values = BoardForm::inUse($board) ? BoardForm::values($board, $sub) : [];
            $cell->table('form_packet_forms')->insert(['tenant_id' => $tenant->id, 'packet_id' => $packetId, 'template_id' => $template['id'],
                'template_version' => $template['version'], 'values' => json_encode((object) $values), 'sort' => 0]);
            foreach ($signers as $i => $s) {
                $cell->table('form_signers')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'packet_id' => $packetId, 'role' => $s['role'],
                    'name' => mb_substr($s['name'], 0, 160), 'email' => $s['email'], 'sign_order' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $signing = app(Signing::class);
            $signing->recordEvent($packetId, null, 'sent', 'IDX agreement sent for signature by RightAlly');
            $signing->notifyNext($packetId, 'https://'.Host::platformHostFor($tenant->slug));
            $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['packet_ulid' => $packetUlid, 'website' => $website, 'signed_at' => null,
                'signed_path' => null, 'updated_at' => now()]);
            $this->audit->record('idx.agreement.sent_for_signature', metadata: ['subscription' => $sub->ulid, 'packet' => $packetUlid, 'signers' => count($signers)], severity: 'notice');

            return true;
        });
    }

    /**
     * Everyone signed: keep a copy and hold it for RightAlly's check (listener for the e-signature's sealed event).
     * IDX11: nothing goes to the MLS until HQ has looked at the check and pressed "Send to the MLS".
     */
    public function sealed(PacketSealed $event): void
    {
        $sub = $this->db()->table('idx_subscriptions')->where('tenant_id', $event->tenantId)->where('packet_ulid', $event->packetUlid)->first();
        if (! $sub instanceof stdClass) {
            return; // not an MLS agreement
        }
        $copy = 'idx/agreements/'.$sub->ulid.'.pdf';
        Storage::disk('local')->put($copy, (string) Storage::disk('local')->get($event->sealedPath));
        $version = $this->db()->table('idx_boards')->where('id', $sub->board_id)->value('agreement_version');
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['signed_path' => $copy, 'signed_at' => now(), 'status' => 'hq_check', 'status_at' => now(),
            'client_note' => null, 'check_result' => null, 'check_issues' => null, 'check_note' => null, 'checked_at' => null,
            'agreement_version' => $version !== null ? (int) $version : null, 'updated_at' => now()]);
        $this->audit->record('idx.agreement.signed', metadata: ['subscription' => $sub->ulid], severity: 'notice', tenantId: (int) $sub->tenant_id);
        CheckIdxAgreement::dispatch((string) $sub->ulid);
    }

    /**
     * Sends the signed agreement to the board's addresses. Unless forced (HQ), the brokerage's website must load over
     * HTTPS first; if it does not, the board goes to "needs fixing" with the reason.
     */
    public function deliver(string $ulid, bool $force, ?User $by = null): bool
    {
        $sub = $this->db()->table('idx_subscriptions')->where('ulid', $ulid)->first();
        $board = $sub instanceof stdClass ? $this->db()->table('idx_boards')->where('id', $sub->board_id)->first() : null;
        $tenant = $sub instanceof stdClass ? Tenant::query()->whereKey((int) $sub->tenant_id)->first() : null;
        if (! $sub instanceof stdClass || ! $board instanceof stdClass || $tenant === null || $sub->signed_path === null || ! self::automatic($board)) {
            return false;
        }
        $website = (string) ($sub->website ?: $this->website((int) $tenant->id));
        if (! $force && ($problem = $this->siteProblem($website, null)) !== null) {
            $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['status' => 'needs_fixing', 'status_at' => now(), 'updated_at' => now(),
                'client_note' => mb_substr('Your agreement is signed, but your website must open before we send it to the MLS: '.$problem, 0, 300)]);
            $this->audit->record('idx.agreement.held', metadata: ['subscription' => $ulid, 'reason' => $problem], severity: 'warning', tenantId: (int) $tenant->id);

            return false;
        }
        $facts = $this->facts($tenant, $sub, $website);
        $mail = Mail::to(self::emails((string) $board->send_to));
        if (($cc = self::emails((string) $board->send_cc)) !== []) {
            $mail->cc($cc);
        }
        if (($bcc = self::emails((string) $board->send_bcc)) !== []) {
            $mail->bcc($bcc);
        }
        $mail->queue(new IdxAgreementMail((string) $board->name, $facts, (string) $sub->signed_path,
            'IDX agreement - '.Str::slug((string) $tenant->name).' - '.$board->code.'.pdf'));
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['status' => 'sent', 'client_note' => null, 'status_at' => now(), 'sent_to_mls_at' => now(),
            'website' => $website, 'updated_at' => now()]);
        $this->audit->record('idx.agreement.sent_to_mls', metadata: ['subscription' => $ulid, 'to' => self::emails((string) $board->send_to), 'forced' => $force, 'by' => $by?->id],
            severity: 'notice', tenantId: (int) $tenant->id);

        return true;
    }

    /**
     * Nightly: each live board's website loads and its home search shows the MLS notice. Problems go to HQ.
     *
     * @return array{checked: int, problems: int}
     */
    public function complianceWatch(): array
    {
        $problems = [];
        $checked = 0;
        $tenants = Tenant::query()->pluck('name', 'id');
        foreach ($this->db()->table('idx_subscriptions')->where('status', 'live')->get() as $sub) {
            $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->first();
            $website = (string) ($sub->website ?: $this->website((int) $sub->tenant_id));
            $notice = $this->noticeFor($sub);
            $problem = $website === '' ? 'No website address found.' : $this->siteProblem(rtrim($website, '/').'/homes', $notice);
            $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['compliance_ok' => $problem === null, 'compliance_note' => $problem !== null ? mb_substr($problem, 0, 300) : null,
                'compliance_checked_at' => now(), 'website' => $website !== '' ? $website : null]);
            $checked++;
            if ($problem !== null) {
                $problems[] = ($tenants[$sub->tenant_id] ?? 'Brokerage').' · '.($board->name ?? 'board').': '.$problem;
            }
        }
        $to = Watchtower::all()['recipients'];
        if ($problems !== [] && $to !== []) {
            Mail::to($to)->queue(new IdxUpdateMail('MLS compliance check: '.count($problems).' website(s) need a look', [...$problems,
                'Open HQ › IDX coverage › Brokerage boards to see each one.']));
        }

        return ['checked' => $checked, 'problems' => count($problems)];
    }

    /** Why a website would fail an MLS review, or null when it opens fine (and shows the notice, if one is given). */
    public function siteProblem(string $url, ?string $notice): ?string
    {
        if (! str_starts_with($url, 'https://')) {
            return 'It needs an https:// address.';
        }
        try {
            $r = $this->http->timeout(15)->withHeaders(['User-Agent' => 'RightAlly MLS compliance check'])->get($url);
        } catch (Throwable) {
            return 'It did not open (no answer from '.parse_url($url, PHP_URL_HOST).').';
        }
        if (! $r->successful()) {
            return 'It answered with an error ('.$r->status().').';
        }
        $body = (string) $r->body();
        if (preg_match('/<input[^>]+type=["\']?password/i', $body) === 1) {
            return 'It asks for a password; MLS reviewers must see it openly.';
        }
        if ($notice !== null && $notice !== '' && ! str_contains(html_entity_decode(strip_tags($body)), $notice)) {
            return 'The MLS notice is missing from the home search page.';
        }

        return null;
    }

    /** The brokerage's website address: its own domain if connected, otherwise its RightAlly website address. */
    public function website(int $tenantId): string
    {
        $domains = TenantDomain::query()->where('tenant_id', $tenantId)->where('purpose', 'website')->get();
        $own = $domains->first(fn (TenantDomain $d): bool => $d->kind->value === 'custom');
        $host = ($own ?? $domains->first())?->host;

        return $host !== null ? 'https://'.$host : '';
    }

    /**
     * The words of the board's MLS notice that never change (the text before any {placeholder}), to look for on the page.
     */
    private function noticeFor(stdClass $sub): ?string
    {
        $source = $sub->mls_authorization_id !== null
            ? MlsSource::query()->whereKey((int) DB::connection(Connections::control())->table('mls_authorizations')->where('id', $sub->mls_authorization_id)->value('mls_source_id'))->first()
            : null;
        $disclaimer = (string) ($source?->rulesProfile->disclaimer ?? '');
        $fixed = trim((string) strtok($disclaimer, '{'));

        return mb_strlen($fixed) >= 20 ? mb_substr($fixed, 0, 80) : null;
    }

    /**
     * @param  list<array{role: string, name: string, email: string, label: string}>  $signers
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function document(stdClass $board, stdClass $sub, Tenant $tenant, string $website, array $signers): array
    {
        $pdf = new Fpdi('P', 'pt', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pages = 0;
        // IDX12: the board's own fields, where the board placed them; its signature lines go to our signers.
        $own = BoardForm::inUse($board) ? BoardForm::fields($board) : [];
        $roles = array_column($signers, 'role');
        $fields = [];
        foreach ($own as $f) {
            if ($f['role'] !== null && ! in_array($f['role'], $roles, true)) {
                $f['role'] = 'brokerage'; // the owner is also the managing broker
            }
            if ($f['source'] === 'skip') {
                continue;
            }
            $fields[] = ['key' => $f['key'], 'type' => $f['type'] === 'date' ? 'text' : $f['type'], 'page' => $f['page'], 'x' => $f['x'], 'y' => $f['y'], 'w' => $f['w'],
                'h' => $f['h'], 'role' => $f['role'], 'required' => $f['role'] !== null, 'label' => $f['label']];
        }
        $boardSigns = array_values(array_unique(array_filter(array_column(array_filter($fields, fn (array $f): bool => $f['type'] === 'signature'), 'role'))));
        if (is_string($board->agreement_path) && $board->agreement_path !== '' && Storage::disk('local')->exists($board->agreement_path)) {
            $src = tempnam(sys_get_temp_dir(), 'idx-agreement-');
            file_put_contents($src, (string) Storage::disk('local')->get($board->agreement_path));
            $count = $pdf->setSourceFile($src);
            for ($i = 1; $i <= $count; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage(is_array($size) ? $size['orientation'] : 'P', is_array($size) ? [$size['width'], $size['height']] : self::PAGE);
                $pdf->useTemplate($tpl);
                $pages++;
            }
            @unlink($src);
        }
        // Our summary and signature page is added unless every signer already signs on the board's own form.
        if ($own !== [] && array_diff($roles, $boardSigns) === []) {
            return [$pdf->Output('S'), $fields];
        }
        $pdf->AddPage('P', self::PAGE);
        $pages++;
        $text = fn (string $s): string => (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        $pdf->SetTextColor(10, 19, 48);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetXY(54, 54);
        $pdf->Cell(504, 22, $text('IDX data agreement: '.$board->name));
        $pdf->SetFont('Helvetica', '', 10);
        $y = 92.0;
        foreach ($this->facts($tenant, $sub, $website) as $label => $value) {
            $pdf->SetXY(54, $y);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(150, 14, $text($label));
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(354, 14, $text($value));
            $y += 16;
        }
        $terms = strtr((string) ($board->agreement_text ?? ''), ['{brokerage}' => $tenant->name, '{mls_id}' => (string) $sub->member_id, '{office_id}' => (string) ($sub->office_id ?? ''),
            '{license}' => (string) $sub->license_number, '{website}' => $website, '{managing_broker}' => (string) $sub->managing_broker_name, '{board}' => (string) $board->name,
            '{date}' => now()->format('F j, Y')]);
        $terms = trim($terms) !== '' ? $terms : 'The brokerage asks '.$board->name.' to license its listing data for display on the website above, through RightAlly as its technology provider, under the MLS\'s IDX rules. The brokerage confirms the details above are correct.';
        $pdf->SetXY(54, $y + 14);
        $pdf->MultiCell(504, 13, $text($terms));
        $blockY = 560.0;
        foreach ($signers as $s) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(54, $blockY);
            $pdf->Cell(504, 14, $text($s['label'].': '.$s['name'].' <'.$s['email'].'>'));
            $pdf->SetDrawColor(150, 160, 185);
            $pdf->Line(54, $blockY + 52, 304, $blockY + 52);
            $pdf->Line(330, $blockY + 52, 470, $blockY + 52);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetXY(54, $blockY + 54);
            $pdf->Cell(250, 10, 'Signature');
            $pdf->SetXY(330, $blockY + 54);
            $pdf->Cell(140, 10, 'Date');
            $fields[] = ['key' => 'sign_'.$s['role'], 'type' => 'signature', 'page' => $pages, 'x' => 54, 'y' => $blockY + 20, 'w' => 250, 'h' => 30, 'role' => $s['role'],
                'required' => true, 'label' => $s['label'].' signature'];
            $fields[] = ['key' => 'date_'.$s['role'], 'type' => 'date_signed', 'page' => $pages, 'x' => 330, 'y' => $blockY + 34, 'w' => 140, 'h' => 16, 'role' => $s['role'],
                'required' => true, 'label' => $s['label'].' date'];
            $blockY += 90;
        }

        return [$pdf->Output('S'), $fields];
    }

    /** @return array<string, string> */
    public function facts(Tenant $tenant, stdClass $sub, string $website): array
    {
        return array_filter(['Brokerage' => (string) $tenant->name, 'Website' => $website, 'MLS member ID' => (string) $sub->member_id,
            'Office ID' => (string) ($sub->office_id ?? ''), 'Real estate licence' => (string) $sub->license_number,
            'Managing broker' => trim((string) $sub->managing_broker_name.' <'.(string) $sub->managing_broker_email.'>'), 'Technology provider' => 'RightAlly',
            'Prepared' => now()->format('F j, Y')], fn (string $v): bool => trim($v) !== '' && trim($v) !== '<>');
    }

    /** @return list<string> */
    public static function emails(string $list): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $e): string => mb_strtolower(trim($e)), preg_split('/[\s,;]+/', $list) ?: []),
            fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
