<?php

declare(strict_types=1);

namespace Modules\Transactions\Policies;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Reading\PageText;
use Throwable;

/**
 * Compliance Knowledge, the library (specs/ai/05): the brokerage's policy manual and memos, kept as passages with
 * their page so every answer can quote its source. Replacing a document summarises what changed and sends it to the
 * agents, who acknowledge it: the broker sees who has read each change.
 */
final class PolicyLibrary
{
    public const CHANGE_FEATURE = 'docs.policy_change';

    private const CHUNK = 1500;

    public function __construct(private readonly TenantContext $tenants, private readonly AiGateway $gateway) {}

    /**
     * Adds a document (a PDF, or pasted text), or a new version of one.
     */
    public function add(string $title, ?UploadedFile $file, ?string $text, ?string $replaces, User $by): string
    {
        $title = mb_substr(trim($title), 0, 160);
        $pages = $file !== null ? (PageText::read((string) file_get_contents($file->getRealPath()), (string) $file->getMimeType())['pages'] ?? [])
            : array_values(array_filter(array_map('trim', preg_split('/\f|\n{4,}/', (string) $text) ?: [])));
        if ($title === '' || $pages === [] || trim(implode('', $pages)) === '') {
            throw ValidationException::withMessages(['file' => 'Upload a PDF with text in it, or paste the policy text, and give it a title.']);
        }
        $db = DB::connection(Connections::cell());
        $old = $replaces !== null ? $db->table('tx_policy_docs')->where('ulid', $replaces)->where('status', 'active')->first() : null;
        if ($replaces !== null && $old === null) {
            throw ValidationException::withMessages(['replaces' => 'That document is not current.']);
        }
        $ulid = (string) Str::ulid();
        $id = $db->table('tx_policy_docs')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'title' => $title, 'version' => $old !== null ? (int) $old->version + 1 : 1,
            'status' => 'active', 'replaces_id' => $old?->id, 'pages' => count($pages), 'uploaded_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($pages as $p => $page) {
            foreach (self::chunks($page) as $chunk) {
                $db->table('tx_policy_chunks')->insert(['tenant_id' => $this->tenants->id(), 'doc_id' => $id, 'page' => $p + 1, 'text' => $chunk]);
            }
        }
        $summary = $old !== null ? $this->changes((int) $old->id, $id, $by) : 'New: '.$title.'.';
        $db->table('tx_policy_docs')->where('id', $id)->update(['change_summary' => $summary]);
        if ($old !== null) {
            $db->table('tx_policy_docs')->where('id', $old->id)->update(['status' => 'superseded', 'updated_at' => now()]);
        }
        $people = User::query()->whereIn('kind', [UserKind::Agent, UserKind::Staff, UserKind::Assistant])->where('status', UserStatus::Active)->get();
        Notification::send($people, new PolicyChangedMail($title, $summary));
        app(AuditLogger::class)->record('transactions.policy.published', metadata: ['title' => $title, 'version' => $old !== null ? (int) $old->version + 1 : 1, 'to' => $people->count()], severity: 'notice');

        return $ulid;
    }

    public function remove(string $ulid, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_policy_docs')->where('ulid', $ulid)->where('status', 'active')->update(['status' => 'removed', 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.policy.removed', metadata: ['document' => $ulid], severity: 'notice');
    }

    public function acknowledge(string $ulid, User $user): void
    {
        $db = DB::connection(Connections::cell());
        $doc = $db->table('tx_policy_docs')->where('ulid', $ulid)->where('status', 'active')->first() ?? abort(404);
        $db->table('tx_policy_acks')->insertOrIgnore(['tenant_id' => $doc->tenant_id, 'doc_id' => $doc->id, 'user_id' => $user->id, 'acknowledged_at' => now()]);
        app(AuditLogger::class)->record('transactions.policy.acknowledged', metadata: ['title' => $doc->title, 'version' => $doc->version], severity: 'info');
    }

    /**
     * Current documents the person has not acknowledged yet, with what changed.
     *
     * @return list<array{ulid: string, title: string, version: int, summary: string}>
     */
    public static function toAcknowledge(User $user): array
    {
        $db = DB::connection(Connections::cell());
        $done = $db->table('tx_policy_acks')->where('user_id', $user->id)->pluck('doc_id')->all();

        return array_values($db->table('tx_policy_docs')->where('status', 'active')->whereNotIn('id', $done === [] ? [0] : $done)
            ->where('created_at', '>=', CarbonImmutable::parse((string) ($user->getAttribute('created_at') ?? '2000-01-01'))->subYear())->orderByDesc('id')->get()
            ->map(fn ($d): array => ['ulid' => (string) $d->ulid, 'title' => (string) $d->title, 'version' => (int) $d->version, 'summary' => (string) ($d->change_summary ?? '')])->all());
    }

    /**
     * The library for the broker: each current document, and who has and has not acknowledged it.
     *
     * @return list<array{ulid: string, title: string, version: int, pages: int, summary: string|null, at: string, acknowledged: int, waiting: list<string>}>
     */
    public static function documents(): array
    {
        $db = DB::connection(Connections::cell());
        $people = User::query()->whereIn('kind', [UserKind::Agent, UserKind::Staff, UserKind::Assistant])->where('status', UserStatus::Active)->get();

        return array_values($db->table('tx_policy_docs')->where('status', 'active')->orderBy('title')->get()->map(function ($d) use ($db, $people): array {
            $acked = $db->table('tx_policy_acks')->where('doc_id', $d->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

            return ['ulid' => (string) $d->ulid, 'title' => (string) $d->title, 'version' => (int) $d->version, 'pages' => (int) $d->pages, 'summary' => $d->change_summary !== null ? (string) $d->change_summary : null,
                'at' => CarbonImmutable::parse((string) $d->created_at)->toDateString(), 'acknowledged' => count($acked),
                'waiting' => array_values($people->reject(fn (User $u): bool => in_array($u->id, $acked, true))->map(fn (User $u): string => $u->name)->all())];
        })->all());
    }

    /**
     * Passages of up to ~1,500 characters, split on paragraphs.
     *
     * @return list<string>
     */
    public static function chunks(string $page): array
    {
        $out = [];
        $buf = '';
        foreach (preg_split('/\n\s*\n/', $page) ?: [] as $para) {
            $para = trim((string) preg_replace('/[ \t]+/', ' ', $para));
            if ($para === '') {
                continue;
            }
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($para) > self::CHUNK) {
                $out[] = $buf;
                $buf = '';
            }
            $buf = $buf === '' ? mb_substr($para, 0, self::CHUNK * 2) : $buf."\n\n".$para;
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /** What changed between versions: the AI summarises the changed passages; without it, the changes are listed. */
    private function changes(int $oldId, int $newId, User $by): string
    {
        $db = DB::connection(Connections::cell());
        $norm = fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));
        $paras = fn (int $id): array => array_values(array_filter(array_map($norm, preg_split('/\n\s*\n/', implode("\n\n", $db->table('tx_policy_chunks')->where('doc_id', $id)->orderBy('id')->pluck('text')->all())) ?: [])));
        $old = $paras($oldId);
        $new = $paras($newId);
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        if ($added === [] && $removed === []) {
            return 'No changes to the wording.';
        }
        $plain = count($added).' passage(s) added or changed, '.count($removed).' removed. First change: “'.mb_substr($added[0] ?? $removed[0], 0, 200).'”';
        try {
            $r = $this->gateway->run(new AiTask(self::CHANGE_FEATURE, 'docs.policy_change', 'policy-change:'.$newId,
                untrustedContent: ['ADDED OR CHANGED:'."\n".implode("\n---\n", array_slice($added, 0, 20)), 'REMOVED:'."\n".implode("\n---\n", array_slice($removed, 0, 20))],
                outputSchema: ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]], userId: $by->id));
            $summary = trim((string) ($r->json['summary'] ?? ''));

            return $summary !== '' ? mb_substr($summary, 0, 1500) : $plain;
        } catch (Throwable $e) {
            report($e);

            return $plain;
        }
    }
}
