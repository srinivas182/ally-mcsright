<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Support\Connections;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roles (plain-English editor with preview), role assignment, offices and teams.
 */
final class AccessController
{
    public function __construct(private readonly Roles $roles, private readonly PermissionRegistry $registry) {}

    public function roles(Request $request): Response
    {
        $this->authorise($request, 'core.roles.manage');
        $roles = $this->roles->all();
        $holders = DB::connection(Connections::cell())->table('user_roles')->selectRaw('role_id, count(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        return Inertia::render('settings/roles', [
            'roles' => $roles->map(fn (Role $r): array => [
                'ulid' => $r->ulid, 'name' => $r->name, 'description' => $r->description, 'locked' => $r->is_locked,
                'template' => $r->template_key, 'members' => (int) ($holders[$r->id] ?? 0), 'permissions' => (object) $this->roles->permissionsOf($r),
                'preview' => $this->roles->describe($this->roles->permissionsOf($r), $r->is_locked),
            ])->values(),
            'catalogue' => collect($this->registry->all())->groupBy('area')->map(fn ($items, $area): array => ['area' => $area, 'permissions' => $items->values()])->values(),
            'missingTemplates' => $this->roles->missingTemplates(),
            'accessReviewDue' => app(AccessReviews::class)->due(),
            'conflicts' => array_map(fn (array $c): array => ['a' => $c[0], 'b' => $c[1], 'why' => $c[2]], $this->registry->conflicts()),
        ]);
    }

    /**
     * Preview as this role (R1): the menu someone with it would see, what it lets them do, and the fields hidden from
     * them. Nothing is saved; works for unsaved edits too.
     */
    public function previewAs(Request $request, NavigationRegistry $navigation, Permissions $permissions): JsonResponse
    {
        $by = $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['permissions' => ['array']]);
        /** @var array<string, string|null> $perms */
        $perms = array_filter((array) ($data['permissions'] ?? []), fn ($v, $k) => $this->registry->has((string) $k), ARRAY_FILTER_USE_BOTH);
        $grants = [];
        foreach ($perms as $key => $scope) {
            $grants[$key] = ($this->registry->get($key)['scoped'] ?? false) && in_array($scope, PermissionRegistry::SCOPES, true) ? (string) $scope : 'tenant';
        }
        $pretend = new User;
        $pretend->forceFill(['id' => 0, 'kind' => UserKind::Staff, 'tenant_id' => $by->tenant_id, 'first_name' => 'Preview', 'last_name' => '', 'status' => 'active']);
        $menu = $permissions->simulate($pretend, $grants, fn (User $u): array => $navigation->for($u));

        return response()->json([
            'menu' => array_map(fn (array $g): array => ['label' => $g['label'], 'items' => array_column($g['items'], 'title')], $menu),
            'lines' => $this->roles->describe($perms),
            'hidden' => Fields::hiddenFor($perms),
        ]);
    }

    public function addTemplate(Request $request, string $key): RedirectResponse
    {
        $user = $this->authorise($request, 'core.roles.manage');
        $role = $this->roles->addTemplate($key, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$role->name} added."]);

        return back();
    }

    public function review(Request $request, AccessReviews $reviews): Response
    {
        $user = $this->reviewer($request);
        $current = $reviews->current();

        return Inertia::render('settings/access-review', [
            'quarter' => AccessReviews::quarter(),
            'started' => $current !== null,
            'completed' => $current?->completed_at,
            'completedBy' => $current?->completed_by !== null ? User::query()->find($current->completed_by)?->name : null,
            'note' => $current?->note,
            'items' => $reviews->items(),
            'history' => DB::connection(Connections::cell())->table('access_reviews')->whereNotNull('completed_at')->orderByDesc('quarter')->limit(8)->get(['quarter', 'completed_at'])
                ->map(fn ($r): array => ['quarter' => (string) $r->quarter, 'completed_at' => CarbonImmutable::parse((string) $r->completed_at)->toDateString()])->values(),
            'canAct' => $user->kind === UserKind::Owner || app(Permissions::class)->allows($user, 'core.access.review'),
        ]);
    }

    public function reviewStart(Request $request, AccessReviews $reviews): RedirectResponse
    {
        $reviews->start($this->reviewer($request));

        return back();
    }

    public function reviewDecide(Request $request, AccessReviews $reviews, int $item): RedirectResponse
    {
        $user = $this->reviewer($request);
        $data = $request->validate(['decision' => ['required', 'in:keep,remove']]);
        $reviews->decide($item, $data['decision'], $user);

        return back();
    }

    public function reviewComplete(Request $request, AccessReviews $reviews): RedirectResponse
    {
        $user = $this->reviewer($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $reviews->complete($user, $data['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Access review signed off.']);

        return back();
    }

    public function reviewExport(Request $request, AccessReviews $reviews): StreamedResponse
    {
        $user = $this->reviewer($request);
        app(AuditLogger::class)->record('core.access.exported', $user, severity: 'warning', label: 'Who can do what');
        $rows = $reviews->matrix();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $v): string => preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v, $row));
            }
            fclose($out);
        }, 'who-can-do-what-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['permissions' => ['array']]);
        /** @var array<string, string|null> $perms */
        $perms = array_filter((array) ($data['permissions'] ?? []), fn ($v, $k) => $this->registry->has((string) $k), ARRAY_FILTER_USE_BOTH);

        return response()->json(['lines' => $this->roles->describe($perms), 'conflicts' => $this->registry->conflictsIn(array_keys($perms))]);
    }

    public function save(Request $request, ?Role $role = null): RedirectResponse
    {
        $user = $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'permissions' => ['array']]);
        $this->roles->save($role, $data['name'], $data['description'] ?? null, (array) ($data['permissions'] ?? []), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role saved.']);

        return back();
    }

    public function assign(Request $request, User $user): RedirectResponse
    {
        $by = $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['role' => ['required', 'string', 'size:26'], 'expires_at' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:200']]);
        $role = Role::query()->where('ulid', $data['role'])->firstOrFail();
        $this->roles->assign($user, $role, $by, isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null, $data['reason'] ?? null);

        return back();
    }

    public function revoke(Request $request, User $user, Role $role): RedirectResponse
    {
        $this->authorise($request, 'core.roles.manage');
        $this->roles->revoke($user, $role);

        return back();
    }

    public function organisation(Request $request): Response
    {
        $this->authorise($request, 'core.organisation.manage');
        $db = DB::connection(Connections::cell());

        return Inertia::render('settings/organisation', [
            'offices' => Office::query()->orderByDesc('is_primary')->orderBy('name')->get()->map(fn (Office $o): array => [
                'ulid' => $o->ulid, 'name' => $o->name, 'primary' => $o->is_primary,
                'people' => $db->table('user_offices')->where('office_id', $o->id)->count(),
            ]),
            'teams' => Team::query()->orderBy('name')->get()->map(fn (Team $t): array => [
                'ulid' => $t->ulid, 'name' => $t->name, 'split' => $t->getAttribute('comp_split_percent'), 'office' => $t->office_id !== null ? Office::query()->whereKey($t->office_id)->value('name') : null,
                'leader' => $t->leader_user_id !== null ? User::query()->find($t->leader_user_id)?->name : null,
                'members' => User::query()->whereIn('id', $db->table('team_members')->where('team_id', $t->id)->pluck('user_id'))->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
            ]),
            'people' => User::query()->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => [
                'ulid' => $u->ulid, 'name' => $u->name, 'offices' => $db->table('user_offices')->where('user_id', $u->id)->pluck('office_id')->map(fn ($id) => Office::query()->whereKey($id)->value('ulid'))->values(),
            ]),
        ]);
    }

    public function saveOffice(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:80'], 'state_code' => ['nullable', 'string', 'size:2']]);
        $office = Office::query()->create($data + ['is_primary' => ! Office::query()->exists()]);
        app(AuditLogger::class)->record('core.office.created', $office, label: $office->name);

        return back();
    }

    public function saveTeam(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'office' => ['nullable', 'string', 'size:26'], 'leader' => ['nullable', 'string', 'size:26'],
            'split_percent' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $team = Team::query()->create([
            'comp_split_percent' => isset($data['split_percent']) ? (string) $data['split_percent'] : null,
            'name' => $data['name'], 'office_id' => isset($data['office']) ? Office::query()->where('ulid', $data['office'])->value('id') : null,
            'leader_user_id' => isset($data['leader']) ? User::query()->where('ulid', $data['leader'])->value('id') : null,
        ]);
        app(AuditLogger::class)->record('core.team.created', $team, label: $team->name);

        return back();
    }

    public function membership(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['user' => ['required', 'string', 'size:26'], 'team' => ['nullable', 'string', 'size:26'], 'office' => ['nullable', 'string', 'size:26'], 'remove' => ['nullable', 'boolean']]);
        $user = User::query()->where('ulid', $data['user'])->firstOrFail();
        $db = DB::connection(Connections::cell());
        if (isset($data['team'])) {
            $team = Team::query()->where('ulid', $data['team'])->firstOrFail();
            ($data['remove'] ?? false)
                ? $db->table('team_members')->where('team_id', $team->id)->where('user_id', $user->id)->delete()
                : $db->table('team_members')->insertOrIgnore(['tenant_id' => $team->getAttribute('tenant_id'), 'team_id' => $team->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        if (isset($data['office'])) {
            $office = Office::query()->where('ulid', $data['office'])->firstOrFail();
            ($data['remove'] ?? false)
                ? $db->table('user_offices')->where('office_id', $office->id)->where('user_id', $user->id)->delete()
                : $db->table('user_offices')->insertOrIgnore(['tenant_id' => $office->getAttribute('tenant_id'), 'user_id' => $user->id, 'office_id' => $office->id, 'is_primary' => false]);
        }
        app(Permissions::class)->forget();
        app(AuditLogger::class)->record('core.organisation.membership_changed', $user, metadata: array_filter(['team' => $data['team'] ?? null, 'office' => $data['office'] ?? null, 'removed' => $data['remove'] ?? false]), label: $user->name);

        return back();
    }

    private function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->kind === UserKind::Owner || app(Permissions::class)->allows($user, 'core.access.review')), 403);

        return $user;
    }

    private function authorise(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can($permission), 403);

        return $user;
    }
}
