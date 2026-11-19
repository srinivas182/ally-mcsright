<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * RightAlly's website content (SG1, specs/marketing/01): pages with a private draft, publishing with the last 20
 * versions kept, restoring a version, blog posts, and an SEO and AEO check with the exact fix for each problem.
 */
final class Website
{
    public const LOCALES = ['en' => 'English', 'es' => 'Español'];

    public const VERSIONS = 20;

    private const RESERVED = ['blog', 'es', 'sitemap.xml', 'robots.txt', 'llms.txt', 'start', 'health-check', 'login', 'app', 'hq', 'r', 'affiliate', 'partners', 'agents', 'preview'];

    public function page(string $locale, string $slug): ?stdClass
    {
        /** @var stdClass|null */
        return $this->db()->table('marketing_pages')->where('locale', $locale)->where('slug', $slug)->first();
    }

    /** @return list<stdClass> */
    public function pages(): array
    {
        return array_values($this->db()->table('marketing_pages')->orderBy('locale')->orderBy('slug')->get()->all());
    }

    /**
     * Create a page (as a draft) or save its draft.
     *
     * @param  array<int, mixed>  $blocks
     */
    public function saveDraft(?string $ulid, string $locale, string $slug, string $title, string $description, array $blocks, User $by): string
    {
        $slug = trim(mb_strtolower($slug), '/');
        if (! isset(self::LOCALES[$locale])) {
            throw ValidationException::withMessages(['locale' => 'Choose English or Spanish.']);
        }
        if ($slug !== '' && (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)?$/', $slug) !== 1 || in_array(explode('/', $slug)[0], self::RESERVED, true))) {
            throw ValidationException::withMessages(['slug' => 'Use lower-case words joined by dashes, like "for-agents" (and not a reserved word).']);
        }
        $title = trim($title);
        $description = trim($description);
        foreach (['title' => $title, 'description' => $description] as $field => $text) {
            $problem = ClaimsCheck::problem($text); // MK1
            if ($problem !== null) {
                throw ValidationException::withMessages([$field => $problem]);
            }
        }
        if ($title === '' || mb_strlen($title) > 70) {
            throw ValidationException::withMessages(['title' => 'Give the page a title of up to 70 characters.']);
        }
        if ($description === '' || mb_strlen($description) > 170) {
            throw ValidationException::withMessages(['description' => 'Give the page a description of up to 170 characters.']);
        }
        $clean = Blocks::clean($blocks);
        $table = $this->db()->table('marketing_pages');
        $existing = $ulid !== null ? $table->clone()->where('ulid', $ulid)->first() : null;
        $existingId = $existing !== null ? (int) $existing->id : null;
        $clash = $table->clone()->where('locale', $locale)->where('slug', $slug)->when($existingId !== null, fn ($q) => $q->where('id', '!=', $existingId))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['slug' => 'Another page already uses this address.']);
        }
        $values = ['locale' => $locale, 'slug' => $slug, 'draft_title' => $title, 'draft_description' => $description, 'draft_blocks' => json_encode($clean),
            'updated_by' => $by->id, 'updated_at' => now()];
        if ($existing === null) {
            $ulid = (string) Str::ulid();
            $table->clone()->insert($values + ['ulid' => $ulid, 'title' => $title, 'description' => $description, 'status' => 'draft', 'created_at' => now()]);
        } else {
            $table->clone()->where('id', $existing->id)->update($values);
            $ulid = (string) $existing->ulid;
        }

        return $ulid;
    }

    /** Copies the draft live and keeps a version. */
    public function publish(string $ulid, User $by): void
    {
        $page = $this->find($ulid);
        if ($page->draft_blocks === null) {
            throw ValidationException::withMessages(['page' => 'There is nothing new to publish.']);
        }
        $db = $this->db();
        $db->table('marketing_pages')->where('id', $page->id)->update(['title' => $page->draft_title, 'description' => $page->draft_description, 'blocks' => $page->draft_blocks,
            'draft_title' => null, 'draft_description' => null, 'draft_blocks' => null, 'status' => 'live', 'published_at' => now(), 'updated_by' => $by->id, 'updated_at' => now()]);
        $db->table('marketing_page_versions')->insert(['page_id' => $page->id, 'title' => $page->draft_title, 'description' => $page->draft_description, 'blocks' => $page->draft_blocks,
            'published_by' => $by->id, 'created_at' => now()]);
        $keep = $db->table('marketing_page_versions')->where('page_id', $page->id)->orderByDesc('id')->limit(self::VERSIONS)->pluck('id');
        $db->table('marketing_page_versions')->where('page_id', $page->id)->whereNotIn('id', $keep)->delete();
        $this->forget();
        app(AuditLogger::class)->record('marketing.page.published', metadata: ['page' => $ulid, 'locale' => $page->locale, 'slug' => $page->slug]);
    }

    /** Puts an earlier version back into the draft (publish to make it live). */
    public function restore(string $ulid, int $versionId, User $by): void
    {
        $page = $this->find($ulid);
        $v = $this->db()->table('marketing_page_versions')->where('page_id', $page->id)->where('id', $versionId)->first()
            ?? throw ValidationException::withMessages(['version' => 'That version is no longer kept.']);
        $this->db()->table('marketing_pages')->where('id', $page->id)->update(['draft_title' => $v->title, 'draft_description' => $v->description, 'draft_blocks' => $v->blocks,
            'updated_by' => $by->id, 'updated_at' => now()]);
    }

    /** Takes a page off the site (the home page stays). */
    public function unpublish(string $ulid, User $by): void
    {
        $page = $this->find($ulid);
        if ($page->slug === '') {
            throw ValidationException::withMessages(['page' => 'The home page cannot be taken down; publish a new version instead.']);
        }
        $this->db()->table('marketing_pages')->where('id', $page->id)->update(['status' => 'draft', 'draft_title' => $page->draft_title ?? $page->title,
            'draft_description' => $page->draft_description ?? $page->description, 'draft_blocks' => $page->draft_blocks ?? $page->blocks, 'updated_by' => $by->id, 'updated_at' => now()]);
        $this->forget();
        app(AuditLogger::class)->record('marketing.page.unpublished', metadata: ['page' => $ulid]);
    }

    /** @return list<array{id: int, title: string, at: string}> */
    public function versions(string $ulid): array
    {
        $page = $this->find($ulid);

        return array_values($this->db()->table('marketing_page_versions')->where('page_id', $page->id)->orderByDesc('id')->get()
            ->map(fn (stdClass $v): array => ['id' => (int) $v->id, 'title' => (string) $v->title, 'at' => (string) $v->created_at])->all());
    }

    public function savePost(?string $ulid, string $locale, string $slug, string $title, string $description, string $body, string $author, ?string $publishAt, bool $live, User $by): string
    {
        $slug = trim(mb_strtolower($slug));
        $errors = [];
        if (! isset(self::LOCALES[$locale])) {
            $errors['locale'] = 'Choose English or Spanish.';
        }
        foreach (['title' => $title, 'description' => $description, 'body' => $body] as $field => $text) {
            $problem = ClaimsCheck::problem($text); // MK1: no other companies named, never "cheap"
            if ($problem !== null) {
                $errors[$field] = $problem;
            }
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 100) {
            $errors['slug'] = 'Use lower-case words joined by dashes.';
        }
        if (trim($title) === '' || mb_strlen($title) > 110) {
            $errors['title'] = 'Give the post a title of up to 110 characters.';
        }
        if (trim($description) === '' || mb_strlen($description) > 170) {
            $errors['description'] = 'Give the post a description of up to 170 characters.';
        }
        if (mb_strlen(trim($body)) < 50 || mb_strlen($body) > 100_000) {
            $errors['body'] = 'Write the post (at least a few sentences).';
        }
        if (trim($author) === '' || mb_strlen($author) > 120) {
            $errors['author'] = 'Name the author.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $table = $this->db()->table('marketing_posts');
        $existing = $ulid !== null ? $table->clone()->where('ulid', $ulid)->first() : null;
        $existingId = $existing !== null ? (int) $existing->id : null;
        if ($table->clone()->where('locale', $locale)->where('slug', $slug)->when($existingId !== null, fn ($q) => $q->where('id', '!=', $existingId))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another post already uses this address.']);
        }
        $when = $live ? ($publishAt !== null && $publishAt !== '' ? CarbonImmutable::parse($publishAt) : ($existing->published_at ?? now())) : null;
        $values = ['locale' => $locale, 'slug' => $slug, 'title' => trim($title), 'description' => trim($description), 'body' => $body, 'author' => trim($author),
            'status' => $live ? 'live' : 'draft', 'published_at' => $when, 'updated_by' => $by->id, 'updated_at' => now()];
        if ($existing === null) {
            $ulid = (string) Str::ulid();
            $table->clone()->insert($values + ['ulid' => $ulid, 'created_at' => now()]);
        } else {
            $table->clone()->where('id', $existing->id)->update($values);
            $ulid = (string) $existing->ulid;
        }
        $this->forget();
        app(AuditLogger::class)->record('marketing.post.saved', metadata: ['post' => $ulid, 'live' => $live]);

        return $ulid;
    }

    /** @return list<stdClass> */
    public function livePosts(string $locale, int $limit = 50): array
    {
        return array_values($this->db()->table('marketing_posts')->where('locale', $locale)->where('status', 'live')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->limit($limit)->get()->all());
    }

    public function livePost(string $locale, string $slug): ?stdClass
    {
        /** @var stdClass|null */
        return $this->db()->table('marketing_posts')->where('locale', $locale)->where('slug', $slug)->where('status', 'live')->where('published_at', '<=', now())->first();
    }

    /**
     * The SEO and AEO check (specs/websites/04): each problem with its fix. Empty means ready.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function check(string $locale, string $slug, string $title, string $description, array $blocks, bool $hasTwin): array
    {
        $fix = [];
        $types = array_column($blocks, 'type');
        if (mb_strlen($title) < 25 || mb_strlen($title) > 60) {
            $fix[] = 'Make the title 25 to 60 characters so search results show it whole (now '.mb_strlen($title).').';
        }
        if (mb_strlen($description) < 70 || mb_strlen($description) > 160) {
            $fix[] = 'Make the description 70 to 160 characters (now '.mb_strlen($description).').';
        }
        if (($types[0] ?? null) !== 'hero') {
            $fix[] = 'Start with a "Top of the page" block: it is the page\'s main heading.';
        }
        if (! in_array('answer', $types, true) && ! in_array('faq', $types, true)) {
            $fix[] = 'Add a short answer or questions and answers, so AI answer engines can quote this page.';
        }
        if (! in_array('cta', $types, true) && ! in_array('pricing', $types, true)) {
            $fix[] = 'Add a call to action (for example "Start now" or "Free Health Check").';
        }
        if (! $hasTwin) {
            $fix[] = $locale === 'en' ? 'Add the Spanish version of this page (same address under /es).' : 'Add the English version of this page.';
        }
        foreach ($blocks as $b) {
            if ($b['type'] === 'compare' && trim((string) ($b['note'] ?? '')) === '') {
                $fix[] = 'Say where the comparison facts come from in its note.';
            }
        }

        return $fix;
    }

    public function forget(): void
    {
        Cache::forget('marketing:sitemap');
    }

    private function find(string $ulid): stdClass
    {
        /** @var stdClass */
        return $this->db()->table('marketing_pages')->where('ulid', $ulid)->first() ?? throw ValidationException::withMessages(['page' => 'That page no longer exists.']);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }
}
