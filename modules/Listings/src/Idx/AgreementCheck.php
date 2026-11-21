<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use stdClass;
use Throwable;

/**
 * IDX11: before a signed MLS agreement goes to the board, RightAlly checks it. Rules first (every signature there, the
 * MLS member ID, license and managing broker filled in and plausible, the copy kept, the website open and showing the
 * MLS notice), then AI reads the same details for anything else a board would send back and writes the note to the
 * brokerage. HQ sees "Clear" or the list, and either sends it to the MLS or sends it back with the note (plus its own).
 * Without AI the rules still run; the decision is always HQ's.
 */
final class AgreementCheck
{
    public const FEATURE = 'idx.agreement_check';

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $tenants) {}

    /**
     * Runs the check and saves the result on the board's track.
     *
     * @return array{result: string, issues: list<array{text: string, for: string}>, note: string, source: string}|null
     */
    public function run(string $ulid): ?array
    {
        $sub = $this->db()->table('idx_subscriptions')->where('ulid', $ulid)->first();
        $board = $sub instanceof stdClass ? $this->db()->table('idx_boards')->where('id', $sub->board_id)->first() : null;
        $tenant = $sub instanceof stdClass ? Tenant::query()->whereKey((int) $sub->tenant_id)->first() : null;
        if (! $sub instanceof stdClass || ! $board instanceof stdClass || $tenant === null) {
            return null;
        }
        $agreements = app(Agreements::class);
        $website = (string) ($sub->website ?: $agreements->website((int) $tenant->id));
        $signers = $this->signers($sub);
        $issues = self::rules($sub, $board, $signers, $website, $website !== '' ? $agreements->siteProblem($website, null) : 'No website address found.');

        $details = self::details($tenant, $sub, $website, $signers, $issues);
        $source = 'rules';
        $note = self::note((string) $board->name, $issues);
        try {
            $hq = Tenant::query()->where('type', 'hq')->first();
            $ai = $hq !== null ? $this->tenants->runAs($hq, fn () => app(AiGateway::class)->run(new AiTask(self::FEATURE, 'idx.agreement_check',
                'idx-agreement-check:'.$ulid.':'.hash('xxh128', $details), inputs: ['board' => (string) $board->name, 'state' => (string) $board->state],
                untrustedContent: [$details], outputSchema: ['type' => 'object', 'required' => ['clear', 'issues', 'note'], 'properties' => [
                    'clear' => ['type' => 'boolean'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']], 'note' => ['type' => 'string']]]))) : null;
            $json = is_array($ai?->json) ? $ai->json : null;
            if ($json !== null) {
                $source = 'ai';
                foreach (array_slice((array) ($json['issues'] ?? []), 0, 8) as $extra) {
                    $text = trim(mb_substr(strip_tags((string) $extra), 0, 240));
                    if ($text !== '' && ! in_array($text, array_column($issues, 'text'), true)) {
                        $issues[] = ['text' => $text, 'for' => 'brokerage'];
                    }
                }
                $written = trim(mb_substr(strip_tags((string) ($json['note'] ?? '')), 0, 1200));
                $note = $issues === [] ? '' : ($written !== '' ? $written : self::note((string) $board->name, $issues));
            }
        } catch (Throwable $e) {
            report($e); // the rules' result stands
        }
        $result = $issues === [] ? 'clear' : 'issues';
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['check_result' => $result, 'check_issues' => json_encode($issues),
            'check_note' => $note !== '' ? $note : null, 'check_source' => $source, 'checked_at' => now(), 'updated_at' => now()]);
        $this->audit->record('idx.agreement.checked', metadata: ['subscription' => $ulid, 'result' => $result, 'issues' => count($issues), 'source' => $source],
            tenantId: (int) $tenant->id);

        return ['result' => $result, 'issues' => $issues, 'note' => $note, 'source' => $source];
    }

    /**
     * What the rules find. "hq" problems are RightAlly's to fix (the board's set-up), not the brokerage's.
     *
     * @param  list<array{name: string, role: string, status: string}>  $signers
     * @return list<array{text: string, for: string}>
     */
    public static function rules(stdClass $sub, stdClass $board, array $signers, string $website, ?string $siteProblem): array
    {
        $out = [];
        $add = function (string $text, string $for = 'brokerage') use (&$out): void {
            $out[] = ['text' => $text, 'for' => $for];
        };
        if ($sub->signed_path === null || ! Storage::disk('local')->exists((string) $sub->signed_path)
            || ! str_starts_with((string) Storage::disk('local')->get((string) $sub->signed_path), '%PDF-')) {
            $add('The signed copy is missing. Prepare the agreement again.', 'hq');
        }
        foreach ($signers as $s) {
            if ($s['status'] !== 'signed') {
                $add($s['name'].' has not signed yet.');
            }
        }
        if ($signers === []) {
            $add('No signatures were found on the agreement.', 'hq');
        }
        if (preg_match('/^[A-Za-z0-9-]{2,30}$/', trim((string) $sub->member_id)) !== 1) {
            $add('The MLS member ID is missing or has more than letters and numbers in it.');
        }
        if (trim((string) $sub->license_number) === '') {
            $add('The real estate license number is missing.');
        } elseif (preg_match('/^[A-Za-z0-9 .-]{3,30}$/', trim((string) $sub->license_number)) !== 1) {
            $add('The real estate license number does not look right.');
        }
        if ((bool) $board->managing_broker_signs) {
            if (mb_strlen(trim((string) $sub->managing_broker_name)) < 3 || ! str_contains(trim((string) $sub->managing_broker_name), ' ')) {
                $add("The managing broker's full name is missing.");
            }
            if (filter_var((string) $sub->managing_broker_email, FILTER_VALIDATE_EMAIL) === false) {
                $add("The managing broker's email is missing or not valid.");
            }
        }
        if ($siteProblem !== null) {
            $add('The website '.($website !== '' ? $website.' ' : '').'is not ready for the MLS: '.$siteProblem);
        }
        if (! Agreements::automatic($board)) {
            $add('This board has no "send to" address yet. Add it in HQ › IDX coverage › MLS boards.', 'hq');
        }

        return $out;
    }

    /**
     * A short note to the brokerage listing what to fix (used when AI is not available).
     *
     * @param  list<array{text: string, for: string}>  $issues
     */
    public static function note(string $board, array $issues): string
    {
        $theirs = array_values(array_filter($issues, fn (array $i): bool => $i['for'] === 'brokerage'));
        if ($theirs === []) {
            return '';
        }

        return 'Thank you for signing. Before we send your agreement to '.$board.", please fix:\n- "
            .implode("\n- ", array_column($theirs, 'text'))."\nThen open Settings › MLS, correct the details and sign again.";
    }

    /** @return list<array{name: string, role: string, status: string}> */
    private function signers(stdClass $sub): array
    {
        if ($sub->packet_ulid === null) {
            return [];
        }
        $cell = DB::connection(Connections::cell());
        $packet = $cell->table('form_packets')->where('ulid', $sub->packet_ulid)->value('id');

        return $packet === null ? [] : array_values($cell->table('form_signers')->where('packet_id', $packet)->orderBy('sign_order')->get(['name', 'role', 'status'])
            ->map(fn (stdClass $s): array => ['name' => (string) $s->name, 'role' => (string) $s->role, 'status' => (string) $s->status])->all());
    }

    /**
     * The details the AI reads (data, never instructions).
     *
     * @param  list<array{name: string, role: string, status: string}>  $signers
     * @param  list<array{text: string, for: string}>  $issues
     */
    private static function details(Tenant $tenant, stdClass $sub, string $website, array $signers, array $issues): string
    {
        return implode("\n", [
            'Brokerage: '.$tenant->name,
            'Website: '.($website !== '' ? $website : '(none)'),
            'MLS member ID: '.$sub->member_id,
            'Office ID: '.$sub->office_id,
            'Real estate license: '.$sub->license_number,
            'Managing broker: '.$sub->managing_broker_name.' <'.$sub->managing_broker_email.'>',
            'Signers: '.implode('; ', array_map(fn (array $s): string => $s['name'].' ('.str_replace('_', ' ', $s['role']).') '.$s['status'], $signers)),
            'Rules found: '.($issues === [] ? 'none' : implode(' ', array_column($issues, 'text'))),
        ]);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }
}
