<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Learn\Models\Article;

/**
 * The brokerage's knowledge base (LN1, specs/learn/01): "how we do things here" pages the broker writes:
 * office procedures, preferred vendors, scripts, the commission-day checklist. A page can be marked "everyone must
 * read"; then each person acknowledges it, and again when it is changed. Search is plain word matching.
 */
final class Knowledge
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{title?: string, category?: string, body?: string, required?: bool|string|null, status?: string|null}  $in
     */
    public function save(?Article $a, array $in, User $by): Article
    {
        if (! Library::manages($by)) {
            throw ValidationException::withMessages(['title' => 'Writing knowledge base pages needs the "Manage training" permission.']);
        }
        $title = trim(strip_tags((string) ($in['title'] ?? '')));
        $body = trim((string) ($in['body'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Give the page a title.';
        }
        if ($body === '') {
            $errors['body'] = 'Write the page.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $data = ['title' => mb_substr($title, 0, 160), 'category' => mb_substr(trim(strip_tags((string) ($in['category'] ?? ''))) ?: 'General', 0, 60),
            'body' => mb_substr($body, 0, 50000), 'required' => filter_var($in['required'] ?? false, FILTER_VALIDATE_BOOL),
            'status' => in_array($in['status'] ?? 'published', ['draft', 'published', 'archived'], true) ? (string) ($in['status'] ?? 'published') : 'published', 'updated_by' => $by->id];
        if ($a === null) {
            $a = Article::query()->create($data + ['version' => 1]);
        } else {
            $changed = $a->body !== $data['body'] || $a->title !== $data['title'];
            $a->update($data + ['version' => $changed ? $a->version + 1 : $a->version]);
        }
        app(AuditLogger::class)->record('learn.article.saved', metadata: ['article' => $a->ulid, 'version' => $a->version, 'required' => $a->required]);

        return $a;
    }

    /**
     * Published pages by category, with whether this person still needs to acknowledge each.
     *
     * @return array<string, list<array{ulid: string, title: string, required: bool, needs_ack: bool, updated: string}>>
     */
    public function index(User $user): array
    {
        $acks = $this->acks($user);
        $out = [];
        foreach (Article::query()->where('status', 'published')->orderBy('category')->orderBy('title')->get() as $a) {
            $out[$a->category][] = ['ulid' => $a->ulid, 'title' => $a->title, 'required' => $a->required,
                'needs_ack' => $a->required && ($acks[$a->id] ?? 0) < $a->version, 'updated' => $a->updated_at->toDateString()];
        }

        return $out;
    }

    /**
     * @return list<array{ulid: string, title: string, category: string, excerpt: string}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($query)) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach (Article::query()->where('status', 'published')->get() as $a) {
            $title = mb_strtolower($a->title);
            $body = mb_strtolower($a->body);
            $score = 0;
            foreach ($words as $w) {
                $score += (str_contains($title, $w) ? 3 : 0) + (str_contains($body, $w) ? 1 : 0);
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'ulid' => $a->ulid, 'title' => $a->title, 'category' => $a->category,
                    'excerpt' => Str::limit(trim((string) preg_replace('/[#*_>`\[\]]+|\s+/', ' ', $a->body)), 160)];
            }
        }
        usort($scored, fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return array_map(fn (array $s): array => ['ulid' => $s['ulid'], 'title' => $s['title'], 'category' => $s['category'], 'excerpt' => $s['excerpt']], array_slice($scored, 0, $limit));
    }

    public function read(Article $a, User $user): bool
    {
        $row = DB::connection(Connections::cell())->table('learn_article_reads')->where('tenant_id', $this->tenants->id())->where('article_id', $a->id)
            ->where('user_id', $user->id)->first();
        if ($row === null) {
            DB::connection(Connections::cell())->table('learn_article_reads')->insert(['tenant_id' => $this->tenants->id(), 'article_id' => $a->id, 'user_id' => $user->id,
                'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $a->required && (int) ($row->version ?? 0) < $a->version;
    }

    public function acknowledge(Article $a, User $user): void
    {
        DB::connection(Connections::cell())->table('learn_article_reads')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'article_id' => $a->id, 'user_id' => $user->id],
            ['version' => $a->version, 'acknowledged_at' => CarbonImmutable::now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('learn.article.acknowledged', metadata: ['article' => $a->ulid, 'version' => $a->version]);
    }

    /** How many required pages this person still has to acknowledge. */
    public function outstanding(User $user): int
    {
        $acks = $this->acks($user);

        return Article::query()->where('status', 'published')->where('required', true)->get()->filter(fn (Article $a): bool => ($acks[$a->id] ?? 0) < $a->version)->count();
    }

    /** @return array<int, int> article id => version acknowledged */
    private function acks(User $user): array
    {
        return DB::connection(Connections::cell())->table('learn_article_reads')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->whereNotNull('acknowledged_at')->pluck('version', 'article_id')->map(fn ($v): int => (int) $v)->all();
    }
}
