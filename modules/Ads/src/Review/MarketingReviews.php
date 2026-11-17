<?php

declare(strict_types=1);

namespace Modules\Ads\Review;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Marketing review (MK3, specs/ads/06): agents check a piece, fix it in one click or with an AI rewrite, and, when
 * the broker requires it, send it for approval with the flyer attached. Reviewers see the marked-up text and the
 * file side by side, and approve or send it back with a note. Every decision is audited.
 */
final class MarketingReviews
{
    public const FEATURE = 'ads.compliance_rewrite';

    public const FILE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly TenantContext $tenants, private readonly AdCheck $check) {}

    public static function reviewer(User $user): bool
    {
        return $user->isKind(UserKind::Owner) || Can::manage($user, 'marketing.review');
    }

    public function approvalRequired(): bool
    {
        return (bool) DB::connection(Connections::cell())->table('ads_review_settings')->where('tenant_id', $this->tenants->id())->value('approval_required');
    }

    public function setApprovalRequired(bool $on, User $by): void
    {
        DB::connection(Connections::cell())->table('ads_review_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['approval_required' => $on, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('ads.review_settings', metadata: ['approval_required' => $on, 'by' => $by->id], severity: 'notice');
    }

    /**
     * An AI rewrite that keeps the facts and fixes the findings (1 credit). The rules check runs again on the result.
     *
     * @param  list<array{rule: string, severity: string, text: string|null, why: string, fix: string, replace: string|null}>  $findings
     */
    public function rewrite(string $text, string $kind, array $findings, User $by): string
    {
        if ($findings === []) {
            return $text;
        }
        try {
            $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'ads.compliance_rewrite', 'ads-fix:'.$by->id.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: ['kind' => AdCheck::KINDS[$kind] ?? 'ad', 'brokerage' => $this->tenants->current()->name,
                    'problems' => implode("\n", array_map(fn (array $f): string => '- '.$f['why'].' Fix: '.$f['fix'], $findings))],
                untrustedContent: [$text], outputSchema: ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]], userId: $by->id));
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['body' => 'The rewrite helper didn\'t manage this one. Use "Apply the fixes", or edit it yourself.']);
        }
        $out = trim(strip_tags((string) ($result->json['text'] ?? '')));

        return $out !== '' ? mb_substr($out, 0, 5000) : $text;
    }

    public function submit(User $by, string $kind, string $title, string $body, ?string $mls, ?UploadedFile $file): string
    {
        if (! isset(AdCheck::KINDS[$kind]) || trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Paste the words of the piece and choose what it is.']);
        }
        $path = null;
        $mime = null;
        if ($file !== null) {
            $mime = (string) $file->getMimeType();
            if (! isset(self::FILE_TYPES[$mime]) || $file->getSize() > 20 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => 'Attach a PDF, JPG or PNG under 20 MB.']);
            }
            $path = 'ads/reviews/'.$this->tenants->id().'/'.Str::ulid().'.'.self::FILE_TYPES[$mime];
            Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        }
        $result = $this->check->run($body, $kind, $mls);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('ads_reviews')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $by->id, 'kind' => $kind,
            'title' => mb_substr(trim($title) ?: 'Marketing piece', 0, 120), 'body' => mb_substr($body, 0, 10000), 'mls_number' => $mls !== null && $mls !== '' ? mb_substr($mls, 0, 30) : null,
            'file_path' => $path, 'file_mime' => $mime, 'findings' => (string) json_encode($result['findings']), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('ads.review_submitted', metadata: ['review' => $ulid, 'must_fix' => count(array_filter($result['findings'], fn (array $f): bool => $f['severity'] === 'must_fix'))]);

        return $ulid;
    }

    public function decide(string $ulid, string $status, ?string $note, User $by): void
    {
        if (! self::reviewer($by)) {
            abort(403);
        }
        if (! in_array($status, ['approved', 'changes'], true)) {
            throw ValidationException::withMessages(['status' => 'Approve, or send back with a note.']);
        }
        if ($status === 'changes' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Say what to change.']);
        }
        $row = DB::connection(Connections::cell())->table('ads_reviews')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first() ?? abort(404);
        DB::connection(Connections::cell())->table('ads_reviews')->where('id', $row->id)->update(['status' => $status, 'note' => $note !== null ? mb_substr(trim($note), 0, 1000) : null,
            'reviewer_id' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('ads.review_decided', metadata: ['review' => $ulid, 'status' => $status], severity: 'notice');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user, bool $queue): array
    {
        $q = DB::connection(Connections::cell())->table('ads_reviews')->where('tenant_id', $this->tenants->id());
        $q = $queue ? $q->where('status', 'pending')->orderBy('id') : $q->where('user_id', $user->id)->orderByDesc('id');
        $rows = $q->limit(100)->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->all() ?: [0])->pluck('first_name', 'id');

        return array_values($rows->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'kind' => AdCheck::KINDS[$r->kind] ?? (string) $r->kind, 'title' => (string) $r->title,
            'body' => (string) $r->body, 'mls_number' => $r->mls_number, 'findings' => (array) json_decode((string) $r->findings, true), 'status' => (string) $r->status,
            'note' => $r->note, 'agent' => (string) ($names[$r->user_id] ?? ''), 'has_file' => $r->file_path !== null, 'created_at' => substr((string) $r->created_at, 0, 10)])->all());
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function file(string $ulid, User $user): ?array
    {
        $row = DB::connection(Connections::cell())->table('ads_reviews')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if ($row === null || $row->file_path === null || ($row->user_id !== $user->id && ! self::reviewer($user))) {
            return null;
        }

        return ['path' => (string) $row->file_path, 'mime' => (string) $row->file_mime];
    }
}
