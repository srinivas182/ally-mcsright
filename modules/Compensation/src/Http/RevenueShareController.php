<?php

declare(strict_types=1);

namespace Modules\Compensation\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Compensation\RevenueShare\Attribution;
use Modules\Compensation\RevenueShare\Holds;
use Modules\Compensation\RevenueShare\Members;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Revenue share trust actions (specs/compensation/02, additions 4, 12, 14). Full screens come with
 * the revenue share screens sprint.
 */
final class RevenueShareController
{
    public function beneficiary(Request $request, Members $members): RedirectResponse
    {
        $data = $request->validate(['beneficiary' => ['required', 'string', 'size:26']]);
        $members->setBeneficiary(self::user($request), User::query()->where('ulid', $data['beneficiary'])->firstOrFail());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Beneficiary saved.']);

        return back();
    }

    public function claim(Request $request, Attribution $attribution): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'evidence' => ['required', 'string', 'max:2000']]);
        $attribution->claim(User::query()->where('ulid', $data['agent'])->firstOrFail(), self::user($request), $data['evidence']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your claim was sent to the brokerage.']);

        return back();
    }

    public function decide(Request $request, string $dispute, Attribution $attribution): RedirectResponse
    {
        $data = $request->validate(['upheld' => ['required', 'boolean'], 'effective_from' => ['required', 'date'], 'note' => ['required', 'string', 'max:500']]);
        $attribution->decide($dispute, (bool) $data['upheld'], CarbonImmutable::parse($data['effective_from']), $data['note'], self::manager($request));

        return back();
    }

    public function release(Request $request, int $hold, Holds $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $holds->release($hold, self::manager($request), (string) ($data['note'] ?? 'Reviewed and released'));

        return back();
    }

    public function reject(Request $request, int $hold, Holds $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $holds->reject($hold, self::manager($request), $data['note']);

        return back();
    }

    public function deceased(Request $request, User $user, Members $members): RedirectResponse
    {
        $by = self::user($request);
        abort_unless($by->isKind(UserKind::Owner), 403);
        $data = $request->validate(['on' => ['required', 'date', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:500']]);
        $members->markDeceased($user, CarbonImmutable::parse($data['on']), $by, $data['note']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->can('compensation.calculations.manage'), 403);

        return $user;
    }
}
