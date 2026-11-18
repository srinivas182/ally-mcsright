<?php

declare(strict_types=1);

namespace Modules\Core\Switches\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Approvals\Approvals;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\SupportAccess;
use Modules\Core\Services\Catalog;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Switches\Areas;
use Modules\Core\Switches\FeatureSwitches;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * Brokerage settings for FL1 (specs/core/16): areas and seats, outside services, approval rules, support access.
 * Thin: every rule lives in the services.
 */
final class PlatformController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function features(Request $request, FeatureSwitches $switches, Areas $areas): Response
    {
        $tenant = $this->tenants->id();
        $summary = $switches->summary($tenant);
        $seatAreas = array_values(array_map(fn (array $a): string => $a['key'], array_filter($summary, fn (array $a): bool => $a['seats'] !== null)));
        $holders = $seatAreas === [] ? collect() : DB::connection(Connections::cell())->table('seat_holders')
            ->where('tenant_id', $tenant)->whereIn('area', $seatAreas)->get()->groupBy('area');
        $people = $seatAreas === [] ? collect() : User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Agent, UserKind::Staff, UserKind::Assistant])
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'ulid', 'first_name', 'last_name']);
        $byId = $people->keyBy('id');

        return Inertia::render('settings/features', [
            'areas' => array_map(fn (array $a): array => $a + ['holders' => ($holders->get($a['key']) ?? collect())
                ->map(fn (stdClass $h): ?array => ($u = $byId->get($h->user_id)) !== null ? ['ulid' => $u->ulid, 'name' => $u->name] : null)->filter()->values()->all()], $summary),
            'people' => $people->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
        ]);
    }

    public function toggle(Request $request, FeatureSwitches $switches): RedirectResponse
    {
        $data = $request->validate(['area' => ['required', 'string', 'max:40'], 'on' => ['required', 'boolean']]);
        $switches->setByBrokerage($data['area'], (bool) $data['on'], $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => (bool) $data['on'] ? 'Switched on.' : 'Switched off. It no longer shows for anyone in your brokerage.']);

        return back();
    }

    public function seat(Request $request, FeatureSwitches $switches): RedirectResponse
    {
        $data = $request->validate(['area' => ['required', 'string', 'max:40'], 'user' => ['required', 'string', 'size:26'], 'give' => ['required', 'boolean']]);
        $person = User::query()->where('ulid', $data['user'])->first() ?? throw ValidationException::withMessages(['user' => 'Choose a person.']);
        (bool) $data['give'] ? $switches->giveSeat($person, $data['area'], $this->user($request)) : $switches->takeSeat($person, $data['area']);

        return back();
    }

    public function services(OutsideServices $services): Response
    {
        return Inertia::render('settings/services', ['services' => $services->summary($this->tenants->id(), 'brokerage'), 'level' => 'brokerage']);
    }

    public function saveService(Request $request, OutsideServices $services, string $service): RedirectResponse
    {
        $data = $this->serviceInput($request);
        $services->save($this->tenants->id(), $service, $data['provider'], $data['test'] ?? [], $data['live'] ?? [], $data['test_contacts'] ?? null, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Run "Check connection" to test it.']);

        return back();
    }

    public function checkService(Request $request, OutsideServices $services, string $service): RedirectResponse
    {
        $mode = (string) $request->validate(['mode' => ['required', 'in:test,live']])['mode'];
        $r = $services->check($this->tenants->id(), $service, $mode, $this->user($request));
        Inertia::flash('toast', ['type' => $r['ok'] ? 'success' : 'error', 'message' => $r['message']]);

        return back();
    }

    public function serviceMode(Request $request, OutsideServices $services, Approvals $approvals, string $service): RedirectResponse
    {
        $mode = (string) $request->validate(['mode' => ['required', 'in:off,test,live']])['mode'];
        $user = $this->user($request);
        if ($mode !== 'live') {
            $services->setMode($this->tenants->id(), $service, $mode, $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => $mode === 'off' ? 'Switched off.' : 'Now in Test mode. Only your test contacts can be reached.']);

            return back();
        }
        if (($blocker = $services->liveBlocker($this->tenants->id(), $service)) !== null) {
            throw ValidationException::withMessages(['mode' => $blocker]);
        }
        $r = $approvals->doOrAsk('services.go_live', 'Switch '.(Catalog::get($service)['label'] ?? $service).' to Live', ['service' => $service], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $r['done'] ? 'Live. Real people and real accounts are now used.' : $r['message']]);

        return back();
    }

    public function approvals(Request $request, Approvals $approvals): Response
    {
        $user = $this->user($request);

        return Inertia::render('settings/approvals', $approvals->summary($user) + ['canSetRules' => $user->kind === UserKind::Owner, 'canApprove' => $approvals->isApprover($user)]);
    }

    public function approvalRule(Request $request, Approvals $approvals): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $data = $request->validate(['action' => ['required', 'string', 'max:60'], 'needed' => ['required', 'integer', 'min:0', 'max:'.Approvals::MAX]]);
        $approvals->setRule($data['action'], (int) $data['needed'], $user);

        return back();
    }

    public function vote(Request $request, Approvals $approvals, string $request_ulid): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);
        $message = $approvals->vote($request_ulid, $this->user($request), (bool) $data['approve'], $data['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function support(Request $request, SupportAccess $access): Response
    {
        abort_unless($this->user($request)->kind === UserKind::Owner, 403);

        return Inertia::render('settings/support-access', $access->summary() + ['hours' => SupportAccess::HOURS]);
    }

    public function supportPolicy(Request $request, SupportAccess $access): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $access->setPolicy((string) $request->validate(['policy' => ['required', 'in:open,ask']])['policy'], $user);

        return back();
    }

    public function supportDecide(Request $request, SupportAccess $access, string $request_ulid): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $access->decide($request_ulid, (bool) $request->validate(['allow' => ['required', 'boolean']])['allow'], $user);

        return back();
    }

    public function supportClose(Request $request, SupportAccess $access): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $access->close($user);

        return back();
    }

    /**
     * @return array{provider: string, test?: array<string, string|null>, live?: array<string, string|null>, test_contacts?: string|null}
     */
    public static function serviceInput(Request $request): array
    {
        /** @var array{provider: string, test?: array<string, string|null>, live?: array<string, string|null>, test_contacts?: string|null} */
        return $request->validate(['provider' => ['required', 'string', 'max:40'], 'test' => ['nullable', 'array', 'max:5'], 'test.*' => ['nullable', 'string', 'max:8000'],
            'live' => ['nullable', 'array', 'max:5'], 'live.*' => ['nullable', 'string', 'max:8000'], 'test_contacts' => ['nullable', 'string', 'max:500']]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
