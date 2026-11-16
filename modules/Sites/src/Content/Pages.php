<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Rendering\Urls;
use Modules\Sites\Sections;
use Modules\Sites\SiteAccess;

/**
 * Pages and their drafts (specs/websites/08): edits go to a draft that only signed previews show; publishing copies
 * it live and keeps a version (the last 50), and any version can be brought back as the draft.
 */
final class Pages
{
    public const KEEP_VERSIONS = 50;

    public function create(Site $site, string $path, string $title, User $by, string $layout = 'standard'): SitePage
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['page' => 'Only owners and staff can add pages.']);
        }
        $path = '/'.trim(strtolower(trim($path)), '/');
        $first = explode('/', ltrim($path, '/'))[0];
        if (preg_match('#^/[a-z0-9-]{1,60}(/[a-z0-9-]{1,60})?$#', $path) !== 1 || in_array($first, Urls::RESERVED, true) || in_array($first, ['blog', 'agents'], true)) {
            throw ValidationException::withMessages(['path' => 'Use lowercase letters, numbers and dashes, like /buyers or /guides/first-time-buyers.']);
        }
        if (SitePage::query()->where('site_id', $site->id)->where('path', $path)->exists()) {
            throw ValidationException::withMessages(['path' => 'A page already uses that address.']);
        }
        $title = mb_substr(trim(strip_tags($title)), 0, 120) ?: 'New page';
        $landing = $layout === 'landing';
        $starter = $landing ? [
            ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => $title, 'subhead' => 'Say in one sentence what people get by leaving their details.', 'search' => false]],
            ['type' => 'features', 'id' => 'why', 'props' => ['title' => 'What you get', 'items' => [['title' => 'A benefit', 'body' => 'Why it matters to them.']]]],
            ['type' => 'contact', 'id' => 'form', 'props' => ['title' => 'Get started', 'body' => '']],
        ] : [['type' => 'text', 'id' => 'intro', 'props' => ['title' => $title, 'body' => 'Start with the answer to the question this page is about.']]];
        // Ad landing pages are hidden from search engines by default (they usually repeat other pages).
        $page = SitePage::query()->create(['site_id' => $site->id, 'path' => $path, 'layout' => $landing ? 'landing' : 'standard', 'title' => $title, 'sections' => [],
            'draft_sections' => Sections::clean($starter), 'noindex' => $landing,
            'position' => (int) SitePage::query()->where('site_id', $site->id)->max('position') + 1, 'in_menu' => false, 'status' => 'draft', 'updated_by' => $by->id]);
        app(AuditLogger::class)->record('sites.page_created', $page, metadata: ['path' => $path]);

        return $page;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public function saveDraft(SitePage $page, array $sections, User $by): SitePage
    {
        self::guard($by, $page);
        $page->forceFill(['draft_sections' => Sections::clean($sections), 'updated_by' => $by->id])->save();

        return $page;
    }

    public function publish(SitePage $page, User $by): SitePage
    {
        self::guard($by, $page);
        $sections = $page->draft_sections ?? $page->sections;
        DB::connection(Connections::cell())->transaction(function () use ($page, $sections, $by): void {
            $page->forceFill(['sections' => $sections, 'draft_sections' => null, 'status' => 'published', 'published_at' => $page->published_at ?? CarbonImmutable::now(),
                'updated_by' => $by->id])->save();
            $db = DB::connection(Connections::cell())->table('site_page_versions');
            $db->insert(['tenant_id' => $page->tenant_id, 'page_id' => $page->id, 'title' => $page->title, 'meta_description' => $page->meta_description,
                'sections' => json_encode($sections), 'created_by' => $by->id, 'created_at' => CarbonImmutable::now()]);
            $keep = DB::connection(Connections::cell())->table('site_page_versions')->where('page_id', $page->id)->orderByDesc('id')->skip(self::KEEP_VERSIONS)->take(1000)->pluck('id');
            if ($keep->isNotEmpty()) {
                DB::connection(Connections::cell())->table('site_page_versions')->whereIn('id', $keep)->delete();
            }
        });
        app(AuditLogger::class)->record('sites.page_published', $page, severity: 'notice');

        return $page;
    }

    public function discardDraft(SitePage $page, User $by): void
    {
        self::guard($by, $page);
        $page->forceFill(['draft_sections' => null])->save();
    }

    /**
     * @return list<array{id: int, at: string, by: string|null, sections: int}>
     */
    public function versions(SitePage $page): array
    {
        $rows = DB::connection(Connections::cell())->table('site_page_versions')->where('page_id', $page->id)->orderByDesc('id')->limit(self::KEEP_VERSIONS)->get();
        $names = User::query()->whereIn('id', $rows->pluck('created_by')->filter())->get()->keyBy('id');

        return array_values($rows->map(fn ($r): array => ['id' => (int) $r->id, 'at' => (string) $r->created_at, 'by' => $names->get($r->created_by)?->name,
            'sections' => count((array) json_decode((string) $r->sections, true))])->all());
    }

    /** Brings a published version back as the draft (publish to make it live again). */
    public function restore(SitePage $page, int $versionId, User $by): void
    {
        self::guard($by, $page);
        $row = DB::connection(Connections::cell())->table('site_page_versions')->where('page_id', $page->id)->where('id', $versionId)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['version' => 'That version no longer exists.']);
        }
        $page->forceFill(['draft_sections' => Sections::clean((array) json_decode((string) $row->sections, true))])->save();
        app(AuditLogger::class)->record('sites.page_restored', $page, metadata: ['version' => $versionId]);
    }

    public function delete(SitePage $page, User $by): void
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['page' => 'Only owners and staff can delete pages.']);
        }
        if ($page->path === '/') {
            throw ValidationException::withMessages(['page' => 'The home page cannot be deleted.']);
        }
        app(AuditLogger::class)->record('sites.page_deleted', $page, metadata: ['path' => $page->path], severity: 'notice');
        $page->delete();
    }

    private static function guard(User $by, SitePage $page): void
    {
        $site = Site::query()->find($page->site_id);
        if ($site === null || ! SiteAccess::canEdit($by, $site)) {
            throw ValidationException::withMessages(['page' => 'You cannot edit this website.']);
        }
    }
}
