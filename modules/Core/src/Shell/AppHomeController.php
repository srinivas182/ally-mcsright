<?php

declare(strict_types=1);

namespace Modules\Core\Shell;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Simple\SimpleMode;

/** Where the installed app opens (PW1): each person's own home page, or sign-in. */
final class AppHomeController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect('/login');
        }
        if (SimpleMode::on($user)) {
            return redirect('/simple');
        }

        return redirect(match (true) {
            $user->isKind(UserKind::HqStaff) => '/hq/tenants',
            $user->isKind(UserKind::Agent, UserKind::Assistant) => '/agent',
            default => '/dashboard',
        });
    }
}
