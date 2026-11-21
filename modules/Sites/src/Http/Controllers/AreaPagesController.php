<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Sites\Content\AreaPages;
use Modules\Sites\SiteAccess;

/** IDX9: Settings › Website › Area pages: add your own words to an area, or switch its page off. */
final class AreaPagesController
{
    public function index(Request $request, AreaPages $areas): Response
    {
        self::manager($request);

        return Inertia::render('settings/website-areas', ['areas' => $areas->all(), 'sample' => $areas->sample(), 'max' => AreaPages::INTRO_MAX]);
    }

    public function update(Request $request, AreaPages $areas, string $area): RedirectResponse
    {
        $areas->save($area, $request->only(['intro', 'hidden']), self::manager($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. The area page shows it within the hour.']);

        return back();
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SiteAccess::manager($user), 403);

        return $user;
    }
}
