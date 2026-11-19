<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Content\Cadence;
use Modules\Marketing\Content\Publisher;
use Modules\Marketing\Content\TenantDesk;
use Modules\Marketing\Content\Topics;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;
use stdClass;

/**
 * Settings → AI blog writer (MK4). Owners and staff turn it on, give it ideas and their own words, and approve each
 * post. An agent with their own desk adds ideas and words and sees how their posts are doing; the broker approves.
 * Everything here is this brokerage's own: other brokerages' desks and drafts are never read.
 */
final class BlogWriterController
{
    public function __construct(private readonly TenantDesk $desks, private readonly TenantContext $tenants) {}

    public function index(Request $request, SiteSetup $sites): Response
    {
        $user = self::user($request);
        $manager = SiteAccess::manager($user);
        $own = $this->desks->desk($user->id);
        $mine = $manager ? $this->desks->desks() : ($own !== null ? [$own] : []);
        $ids = array_map(fn (stdClass $d): int => (int) $d->id, $mine);
        $drafts = DB::connection(Connections::control())->table('content_drafts')->where('tenant_id', (int) $this->tenants->id())->whereIn('desk_id', $ids === [] ? [0] : $ids);
        $site = $sites->brokerageSite();
        $info = $site !== null ? (array) $site->info : [];
        $names = User::query()->whereIn('id', array_filter(array_map(fn (stdClass $d): ?int => $d->user_id !== null ? (int) $d->user_id : null, $mine)))->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);

        return Inertia::render('settings/blog-writer', [
            'included' => TenantDesk::included($user),
            'manager' => $manager,
            'hasSite' => $sites->brokerageSite() !== null,
            'defaults' => ['state' => strtoupper((string) ($info['state'] ?? '')), 'cities' => (string) ($info['area'] ?? '')],
            'states' => Topics::STATES,
            'agents' => $manager ? User::query()->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values() : [],
            'desks' => array_map(function (stdClass $d) use ($names): array {
                $inputs = (array) json_decode((string) ($d->inputs ?? '{}'), true);

                return ['id' => (int) $d->id, 'agent' => $d->user_id !== null ? (int) $d->user_id : null, 'author' => $d->user_id !== null ? (string) ($names[(int) $d->user_id] ?? 'Agent') : null,
                    'state' => (string) $d->state, 'mode' => (string) $d->mode, 'level' => (int) $d->level, 'allowance' => Cadence::allowance($d), 'paused' => $d->paused_reason,
                    'cities' => implode(', ', (array) json_decode((string) ($d->cities ?? '[]'), true)),
                    'ideas' => implode("\n", (array) ($inputs['ideas'] ?? [])), 'words' => (string) ($inputs['words'] ?? '')];
            }, $mine),
            'drafts' => $drafts->clone()->whereIn('status', ['review', 'skipped'])->where('created_at', '>', now()->subDays(14))->orderBy('status')->orderByDesc('id')->limit(50)->get()
                ->map(fn (stdClass $d): array => ['ulid' => (string) $d->ulid, 'city' => (string) $d->city, 'status' => (string) $d->status, 'title' => (string) $d->title,
                    'description' => (string) $d->description, 'body' => (string) $d->body, 'note' => $d->note, 'faq' => (array) json_decode((string) ($d->faq ?? '[]'), true),
                    'facts' => (array) json_decode((string) ($d->facts ?? '[]'), true), 'created_at' => (string) $d->created_at])->values(),
            'published' => $drafts->clone()->where('status', 'published')->orderByDesc('reviewed_at')->limit(20)->get(['title', 'published_url', 'reviewed_at']),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate(['mode' => ['nullable', 'in:off,test,live'], 'state' => ['nullable', 'string', 'size:2'], 'cities' => ['nullable', 'string', 'max:800'],
            'ideas' => ['nullable', 'string', 'max:5000'], 'words' => ['nullable', 'string', 'max:3000'], 'agent' => ['nullable', 'integer', 'min:1']]);
        $this->desks->save(self::user($request), $d);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. It starts at 3 posts a week, each for you to approve; more unlocks as Google indexes them.']);

        return back();
    }

    public function approve(Request $request, string $draft, Publisher $publisher): RedirectResponse
    {
        $d = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:300'], 'body' => ['nullable', 'string', 'max:60000']]);
        $url = $publisher->approveForTenant((int) $this->tenants->id(), $draft, self::user($request), array_filter($d, fn ($v): bool => is_string($v) && $v !== ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Published: '.$url]);

        return back();
    }

    public function reject(Request $request, string $draft, Publisher $publisher): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(SiteAccess::manager($user), 403);
        $d = $request->validate(['why' => ['nullable', 'string', 'max:300']]);
        $publisher->reject($draft, $user, (string) ($d['why'] ?? ''), (int) $this->tenants->id());

        return back();
    }

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
}
