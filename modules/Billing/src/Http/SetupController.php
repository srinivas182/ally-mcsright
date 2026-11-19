<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Onboarding\Setup;
use Modules\Core\Identity\Enums\UserKind;

/** MK2: the owner's "Get set up" page, and the one-tap website. */
final class SetupController
{
    public function show(Request $request, Setup $setup): Response
    {
        $owner = self::owner($request);
        $request->session()->put('setup.seen', true);

        return Inertia::render('setup/index', ['firstName' => $owner->first_name] + $setup->for($owner));
    }

    public function website(Request $request, Setup $setup): RedirectResponse
    {
        $setup->publishWebsite(self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your website is live. Change anything in Website settings.']);

        return redirect('/setup');
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);

        return $user;
    }
}
