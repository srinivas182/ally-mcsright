<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Prospector\DncRegistry;
use Modules\Crm\Prospector\Prospector;

/** Lead-IQ Prospector (LQ2, specs/grow/15). */
final class ProspectorController
{
    public function index(Request $request, Prospector $prospector, DncRegistry $dnc): Response
    {
        $user = self::user($request);
        $broker = $user->isKind(UserKind::Owner) || $user->can('crm.leads.manage');

        return Inertia::render('crm/prospector', [
            'prospects' => $prospector->list($user), 'broker' => $broker, 'feed' => $prospector->feedAvailable(),
            'settings' => $broker ? $prospector->settings() : null,
            'agents' => $broker ? User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all() : [],
            'registry' => $dnc->imports(),
        ]);
    }

    public function fsbo(Request $request, Prospector $prospector): RedirectResponse
    {
        $d = $request->validate(['street' => ['required', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:80'], 'state' => ['nullable', 'string', 'size:2'],
            'zip' => ['nullable', 'string', 'regex:/^\d{5}$/'], 'note' => ['nullable', 'string', 'max:200']]);
        $prospector->addFsbo(self::user($request), (string) $d['street'], $d['city'] ?? null, $d['state'] ?? null, $d['zip'] ?? null, $d['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added to your prospects.']);

        return back();
    }

    public function phone(Request $request, string $prospect, Prospector $prospector): RedirectResponse
    {
        $status = $prospector->setPhone($prospect, (string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'], self::user($request));
        Inertia::flash('toast', ['type' => $status === DncRegistry::CLEAR ? 'success' : 'warning', 'message' => DncRegistry::words($status)]);

        return back();
    }

    public function owner(Request $request, string $prospect, Prospector $prospector): RedirectResponse
    {
        $prospector->findOwner($prospect, self::user($request));

        return back();
    }

    public function contact(Request $request, string $prospect, Prospector $prospector): RedirectResponse
    {
        return redirect('/contacts/'.$prospector->toContact($prospect, self::user($request))->ulid);
    }

    public function dismiss(Request $request, string $prospect, Prospector $prospector): RedirectResponse
    {
        $prospector->dismiss($prospect, self::user($request));

        return back();
    }

    public function settings(Request $request, Prospector $prospector): RedirectResponse
    {
        $user = self::broker($request);
        $prospector->saveSettings($request->only(['agent_ids', 'per_agent_daily', 'zips']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prospector settings saved.']);

        return back();
    }

    public function registry(Request $request, DncRegistry $dnc): RedirectResponse
    {
        $user = self::broker($request);
        $d = $request->validate(['file' => ['required', 'file', 'mimes:txt,csv', 'max:51200'], 'downloaded_on' => ['required', 'date']]);
        $counts = $dnc->import((string) file_get_contents($request->file('file')?->getRealPath() ?: ''), CarbonImmutable::parse((string) $d['downloaded_on']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => number_format(array_sum($counts)).' numbers loaded for area code(s) '.implode(', ', array_keys($counts)).'.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner) || $user->can('crm.leads.manage')), 403);

        return $user;
    }

    private static function broker(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->isKind(UserKind::Owner) || $user->can('crm.leads.manage'), 403);

        return $user;
    }
}
