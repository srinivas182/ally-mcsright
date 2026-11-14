<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Plain-English packet summaries (specs/forms/01, part 5b) in English and Spanish, written by AI from the
 * filled-in terms only and then checked in code: every amount and date in the summary must appear in the
 * documents, or that line is dropped. The agent approves the summary before signers see it; if the terms
 * change afterwards it is stale and hidden until regenerated and approved again. The disclaimer is added in
 * code, never left to the model.
 */
final class Summaries
{
    public const FEATURE = 'forms.packet_summary';

    public const DISCLAIMER = [
        'en' => 'This summary helps you read the documents. It is not legal advice, and it does not change them: the documents you sign are what count. Ask your agent or a lawyer about anything you are unsure of.',
        'es' => 'Este resumen le ayuda a leer los documentos. No es asesoría legal y no los modifica: lo que cuenta son los documentos que usted firma. Pregunte a su agente o a un abogado cualquier duda.',
    ];

    private const POINTS = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['topic', 'text'], 'properties' => ['topic' => ['type' => 'string'], 'text' => ['type' => 'string']]]];

    private const SCHEMA = [
        'type' => 'object',
        'required' => ['headline', 'points', 'deadlines', 'agreeing_to', 'unusual', 'es'],
        'properties' => [
            'headline' => ['type' => 'string'],
            'points' => self::POINTS,
            'deadlines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['label', 'date', 'rule'],
                'properties' => ['label' => ['type' => 'string'], 'date' => ['type' => 'string'], 'rule' => ['type' => 'string']]]],
            'agreeing_to' => ['type' => 'array', 'items' => ['type' => 'string']],
            'unusual' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['text', 'why'], 'properties' => ['text' => ['type' => 'string'], 'why' => ['type' => 'string']]]],
            'es' => ['type' => 'object', 'required' => ['headline', 'points', 'agreeing_to'], 'properties' => ['headline' => ['type' => 'string'], 'points' => self::POINTS,
                'agreeing_to' => ['type' => 'array', 'items' => ['type' => 'string']]]],
        ],
    ];

    public function __construct(private readonly AiGateway $gateway, private readonly Packets $packets, private readonly TenantContext $tenants) {}

    /** Writes (or rewrites) the summary as a draft for the agent to check. */
    public function generate(string $packetUlid, User $by): void
    {
        [$packet] = $this->packets->packet($packetUlid, $by);
        if (in_array($packet->status, ['completed', 'voided'], true)) {
            throw ValidationException::withMessages(['summary' => 'This packet is finished.']);
        }
        $terms = self::terms((int) $packet->id);
        if ($terms === []) {
            throw ValidationException::withMessages(['summary' => 'Fill in the forms first; the summary is written from what they say.']);
        }
        $names = DB::connection(Connections::cell())->table('form_packet_forms')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')
            ->where('form_packet_forms.packet_id', $packet->id)->orderBy('form_packet_forms.sort')->pluck('form_templates.name')->implode(', ');
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'forms.packet_summary', 'forms-summary:'.$packetUlid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['forms' => $names, 'today' => CarbonImmutable::now()->toFormattedDateString()],
            untrustedContent: array_map(fn (array $t): string => "{$t['form']} — {$t['label']}: {$t['value']}", $terms),
            outputSchema: self::SCHEMA, userId: $by->id));
        $summary = self::enforce((array) $result->json, $terms);
        DB::connection(Connections::cell())->table('form_packets')->where('id', $packet->id)->update(['summary' => Crypt::encryptString((string) json_encode($summary)),
            'summary_hash' => self::hash((int) $packet->id), 'summary_generated_at' => now(), 'summary_approved_by' => null, 'summary_approved_at' => null]);
        app(Signing::class)->recordEvent((int) $packet->id, null, 'summary_written', 'Plain-English summary written for '.$by->name.' to check'
            .($summary['removed'] > 0 ? " ({$summary['removed']} line(s) removed: amounts or dates not found in the documents)" : ''));
    }

    public function approve(string $packetUlid, User $by): void
    {
        [$packet] = $this->packets->packet($packetUlid, $by);
        if ($packet->summary === null || $packet->summary_hash !== self::hash((int) $packet->id)) {
            throw ValidationException::withMessages(['summary' => 'The terms changed since this summary was written. Write it again, then approve it.']);
        }
        DB::connection(Connections::cell())->table('form_packets')->where('id', $packet->id)->update(['summary_approved_by' => $by->id, 'summary_approved_at' => now()]);
        app(Signing::class)->recordEvent((int) $packet->id, null, 'summary_approved', 'Plain-English summary approved by '.$by->name);
        app(AuditLogger::class)->record('forms.summary.approved', metadata: ['packet' => $packetUlid]);
    }

    /**
     * The summary and where it stands, for the agent.
     *
     * @return array{status: string, content: array<string, mixed>|null, generated_at: ?string, approved_by: ?string}
     */
    public static function forAgent(\stdClass $packet): array
    {
        $content = self::content($packet);
        $status = $content === null ? 'none' : ($packet->summary_hash !== self::hash((int) $packet->id) ? 'stale' : ($packet->summary_approved_at !== null ? 'approved' : 'draft'));

        return ['status' => $status, 'content' => $content, 'generated_at' => $packet->summary_generated_at,
            'approved_by' => $packet->summary_approved_by !== null ? User::query()->find((int) $packet->summary_approved_by)?->name : null];
    }

    /**
     * What signers see: only an approved summary of the current terms.
     *
     * @return array<string, mixed>|null
     */
    public static function forSigner(\stdClass $packet): ?array
    {
        $agent = self::forAgent($packet);
        if ($agent['status'] !== 'approved' || $agent['content'] === null) {
            return null;
        }
        $c = $agent['content'];

        return ['en' => ['headline' => $c['headline'], 'points' => $c['points'], 'agreeing_to' => $c['agreeing_to'], 'disclaimer' => self::DISCLAIMER['en']],
            'es' => ['headline' => $c['es']['headline'], 'points' => $c['es']['points'], 'agreeing_to' => $c['es']['agreeing_to'], 'disclaimer' => self::DISCLAIMER['es']],
            'deadlines' => $c['deadlines']];
    }

    /**
     * Puts chosen deadlines on the deal's key dates (the deal timeline, with reminders).
     *
     * @param  list<array{label: string, date: string}>  $dates
     */
    public function addDates(string $packetUlid, array $dates, User $by): int
    {
        [$packet, $deal] = $this->packets->packet($packetUlid, $by);
        $db = DB::connection(Connections::cell());
        $added = 0;
        foreach ($dates as $d) {
            $date = self::date($d['date']);
            $label = mb_substr(trim($d['label']), 0, 80);
            if ($date === null || $label === '') {
                throw ValidationException::withMessages(['dates' => "Give \"{$d['label']}\" a date before adding it."]);
            }
            if (! $db->table('tx_dates')->where('deal_id', $deal->id)->where('label', $label)->where('due_on', $date)->exists()) {
                $db->table('tx_dates')->insert(['tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id, 'label' => $label, 'due_on' => $date, 'source' => 'forms',
                    'created_at' => now(), 'updated_at' => now()]);
                $added++;
            }
        }
        app(Signing::class)->recordEvent((int) $packet->id, null, 'dates_added', "{$added} key date(s) added to the deal by {$by->name}");

        return $added;
    }

    /**
     * Every filled-in term (not signatures) with its form and label.
     *
     * @return list<array{form: string, label: string, value: string, type: string}>
     */
    public static function terms(int $packetId): array
    {
        $out = [];
        $db = DB::connection(Connections::cell());
        foreach ($db->table('form_packet_forms')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')->where('form_packet_forms.packet_id', $packetId)
            ->orderBy('form_packet_forms.sort')->get(['form_packet_forms.values', 'form_templates.name', 'form_templates.fields']) as $f) {
            $values = (array) json_decode((string) $f->values, true);
            foreach ((array) json_decode((string) $f->fields, true) as $field) {
                $value = trim((string) ($values[$field['key']] ?? ''));
                if ($value === '' || in_array($field['type'], Templates::SIGNER_FIELDS, true)) {
                    continue;
                }
                $out[] = ['form' => (string) $f->name, 'label' => (string) ($field['label'] ?? $field['key']), 'value' => $field['type'] === 'checkbox' ? ($value === '1' ? 'Yes' : 'No') : $value,
                    'type' => (string) $field['type']];
            }
        }

        return $out;
    }

    public static function hash(int $packetId): string
    {
        return hash('sha256', (string) json_encode(self::terms($packetId)));
    }

    /** Y-m-d for "06/30/2026", "2026-06-30" or "June 30, 2026"; null otherwise. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['m/d/Y', 'n/j/Y', 'Y-m-d', 'F j, Y', 'M j, Y', 'j F Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($d !== null && $d->format($format) === $value) {
                return $d->toDateString();
            }
        }

        return null;
    }

    /**
     * The checks that keep the summary honest.
     *
     * @param  array<string, mixed>  $json
     * @param  list<array{form: string, label: string, value: string, type: string}>  $terms
     * @return array<string, mixed>
     */
    public static function enforce(array $json, array $terms): array
    {
        $text = implode(' ', array_column($terms, 'value'));
        $numbers = self::numbers($text);
        $dates = array_values(array_filter(array_map(fn (string $d): ?string => self::date($d), self::dateStrings($text))));
        $removed = 0;
        $keep = function (string $line) use ($numbers, $dates, &$removed): bool {
            $ok = array_diff(self::numbers($line), $numbers) === [] && array_diff(array_filter(array_map(fn (string $d): ?string => self::date($d), self::dateStrings($line))), $dates) === [];
            $removed += $ok ? 0 : 1;

            return $ok;
        };
        $points = fn (mixed $list): array => array_values(array_filter(array_map(fn ($p): array => ['topic' => mb_substr((string) ($p['topic'] ?? 'Other'), 0, 40),
            'text' => mb_substr((string) ($p['text'] ?? ''), 0, 400)], array_slice((array) $list, 0, 8)), fn (array $p): bool => $p['text'] !== '' && $keep($p['text'])));
        $lines = fn (mixed $list, int $max): array => array_values(array_filter(array_map(fn ($l): string => mb_substr((string) $l, 0, 300), array_slice((array) $list, 0, $max)),
            fn (string $l): bool => $l !== '' && $keep($l)));
        $headline = (string) ($json['headline'] ?? '');
        $es = (array) ($json['es'] ?? []);
        $esHeadline = (string) ($es['headline'] ?? '');

        // Deadlines: a date the model gives must be written in the documents; every date field is a key date.
        $deadlines = [];
        foreach (array_slice((array) ($json['deadlines'] ?? []), 0, 15) as $d) {
            $date = self::date((string) ($d['date'] ?? ''));
            $deadlines[] = ['label' => mb_substr((string) ($d['label'] ?? ''), 0, 80), 'date' => $date !== null && in_array($date, $dates, true) ? $date : '',
                'rule' => mb_substr((string) ($d['rule'] ?? ''), 0, 200)];
        }
        foreach ($terms as $t) {
            $date = $t['type'] === 'date' ? self::date($t['value']) : null;
            if ($date !== null && ! in_array($date, array_column($deadlines, 'date'), true)) {
                $deadlines[] = ['label' => mb_substr($t['label'], 0, 80), 'date' => $date, 'rule' => 'As written in '.$t['form']];
            }
        }

        return [
            'headline' => $keep($headline) ? mb_substr($headline, 0, 300) : '',
            'points' => $points($json['points'] ?? []),
            'deadlines' => array_values(array_filter($deadlines, fn (array $d): bool => $d['label'] !== '')),
            'agreeing_to' => $lines($json['agreeing_to'] ?? [], 5),
            'unusual' => array_values(array_map(fn ($u): array => ['text' => mb_substr((string) ($u['text'] ?? ''), 0, 300), 'why' => mb_substr((string) ($u['why'] ?? ''), 0, 300)],
                array_slice((array) ($json['unusual'] ?? []), 0, 8))),
            'es' => ['headline' => $keep($esHeadline) ? mb_substr($esHeadline, 0, 300) : '', 'points' => $points($es['points'] ?? []), 'agreeing_to' => $lines($es['agreeing_to'] ?? [], 5)],
            'removed' => $removed,
        ];
    }

    /**
     * Amounts and other figures of 2+ digits, as digit strings without separators or trailing ".00"
     * ("$485,000.00" → "485000"; "485.000" Spanish style too).
     *
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        $text = (string) preg_replace('~\b\d{1,2}/\d{1,2}/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b~', ' ', $text); // dates are checked separately
        preg_match_all('/\d[\d,.]*\d|\d/', $text, $m);
        $out = [];
        foreach ($m[0] as $n) {
            $n = (string) preg_replace('/[.,]00$/', '', $n);
            $digits = (string) preg_replace('/\D/', '', $n);
            if (strlen($digits) >= 2) {
                $out[] = ltrim($digits, '0') === '' ? '0' : ltrim($digits, '0');
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function dateStrings(string $text): array
    {
        preg_match_all('~\b\d{1,2}/\d{1,2}/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b|\b(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\b~', $text, $m);

        return $m[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function content(\stdClass $packet): ?array
    {
        if ($packet->summary === null) {
            return null;
        }
        $json = json_decode(Crypt::decryptString((string) $packet->summary), true);

        return is_array($json) ? $json : null;
    }
}
