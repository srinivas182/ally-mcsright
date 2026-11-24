<?php

declare(strict_types=1);

namespace Modules\Compensation\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Recruiters\Recruiters;

/** CM4b: Commissions › Recruiters. Only the brokerage adds recruiters and sets how they are paid. */
final class RecruitersController
{
    public function index(Request $request, Recruiters $recruiters): Response
    {
        $user = self::can($request, 'compensation.plans.view');

        return Inertia::render('compensation/recruiters', [
            'recruiters' => $recruiters->list(),
            'settings' => $recruiters->settings(),
            'canManage' => $user->can('compensation.plans.manage'),
        ]);
    }

    public function store(Request $request, Recruiters $recruiters): RedirectResponse
    {
        $by = self::can($request, 'compensation.plans.manage');
        $data = $request->validate(['first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc', 'max:160'], 'bonus' => ['nullable', 'numeric', 'min:0', 'max:100000']]);
        $recruiters->add($data['email'], $data['first_name'], $data['last_name'], isset($data['bonus']) ? (int) round((float) $data['bonus'] * 100) : null, $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['first_name']} is a recruiter. They get an email to set up their account."]);

        return back();
    }

    public function destroy(Request $request, int $recruiter, Recruiters $recruiters): RedirectResponse
    {
        $recruiters->remove($recruiter, self::can($request, 'compensation.plans.manage'));

        return back();
    }

    public function settings(Request $request, Recruiters $recruiters): RedirectResponse
    {
        $by = self::can($request, 'compensation.calculations.manage');
        $data = $request->validate(['bonus' => ['required', 'numeric', 'min:0', 'max:100000'], 'share_levels' => ['required', 'integer', 'min:0', 'max:2'], 'confirmed' => ['boolean']]);
        $recruiters->saveSettings((int) round((float) $data['bonus'] * 100), (int) $data['share_levels'], (bool) ($data['confirmed'] ?? false), $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recruiter pay saved.']);

        return back();
    }

    private static function can(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can($permission), 403);

        return $user;
    }
}
