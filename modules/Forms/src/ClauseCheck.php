<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The clause check (specs/forms/01, part 5b): special terms typed into a packet that are not from the brokerage's
 * clause library, found BEFORE sending (leaders audit after submission). Depending on the brokerage setting it
 * only warns, or the broker or office staff must approve the wording before the packet can be sent. Pure text
 * comparison: no AI, so it never misses a word and never invents one.
 */
final class ClauseCheck
{
    public const SETTINGS = ['off', 'warn', 'require'];

    /** Typed text shorter than this (names, addresses, short notes) is not treated as a special term. */
    private const MIN_LENGTH = 60;

    /**
     * Free text in the packet that is not library wording.
     *
     * @return list<array{form: string, label: string, text: string}>
     */
    public static function review(int $packetId): array
    {
        $db = DB::connection(Connections::cell());
        $library = $db->table('form_clauses')->pluck('body')->map(fn ($b): string => self::normalise((string) $b))->filter(fn (string $b): bool => $b !== '')
            ->sortByDesc(fn (string $b): int => mb_strlen($b))->values()->all();
        $out = [];
        foreach (Summaries::terms($packetId) as $t) {
            if ($t['type'] !== 'text' || mb_strlen($t['value']) < self::MIN_LENGTH) {
                continue;
            }
            $rest = self::normalise($t['value']);
            foreach ($library as $clause) {
                $rest = str_replace($clause, ' ', $rest);
            }
            $rest = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\pL\pN$%\s]/u', ' ', $rest)));
            if (mb_strlen($rest) >= self::MIN_LENGTH / 2) {
                $out[] = ['form' => $t['form'], 'label' => $t['label'], 'text' => mb_strlen($t['value']) > 600 ? mb_substr($t['value'], 0, 597).'…' : $t['value']];
            }
        }

        return $out;
    }

    public static function setting(): string
    {
        return (string) (DB::connection(Connections::cell())->table('form_settings')->value('clause_review') ?? 'warn');
    }

    /** Whether the current special terms are approved (an edit after approval needs a new approval). */
    public static function approved(\stdClass $packet): bool
    {
        return $packet->clauses_approved_hash !== null && $packet->clauses_approved_hash === self::hash((int) $packet->id);
    }

    public static function sendProblem(\stdClass $packet): ?string
    {
        if (self::setting() !== 'require' || self::approved($packet) || self::review((int) $packet->id) === []) {
            return null;
        }

        return 'Special terms that are not from your clause library need approval by the broker or office staff before sending.';
    }

    public function approve(string $packetUlid, User $by): void
    {
        if (! Templates::manages($by)) {
            throw ValidationException::withMessages(['clauses' => 'Only the broker or office staff can approve special terms.']);
        }
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('ulid', $packetUlid)->first() ?? throw ValidationException::withMessages(['clauses' => 'Packet not found.']);
        $db->table('form_packets')->where('id', $p->id)->update(['clauses_approved_by' => $by->id, 'clauses_approved_hash' => self::hash((int) $p->id)]);
        app(Signing::class)->recordEvent((int) $p->id, null, 'clauses_approved', 'Special terms approved by '.$by->name);
        app(AuditLogger::class)->record('forms.clauses.approved', metadata: ['packet' => $packetUlid, 'terms' => count(self::review((int) $p->id))], severity: 'notice');
    }

    public static function setSetting(string $setting, User $by): void
    {
        if (! Templates::manages($by) || ! in_array($setting, self::SETTINGS, true)) {
            throw ValidationException::withMessages(['clause_review' => 'Choose off, warn or require.']);
        }
        DB::connection(Connections::cell())->table('form_settings')->updateOrInsert(['tenant_id' => app(TenantContext::class)->id()],
            ['clause_review' => $setting, 'updated_at' => now(), 'created_at' => now()]);
    }

    private static function hash(int $packetId): string
    {
        return hash('sha256', (string) json_encode(self::review($packetId)));
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }
}
