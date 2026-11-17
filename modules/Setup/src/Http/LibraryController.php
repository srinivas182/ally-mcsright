<?php

declare(strict_types=1);

namespace Modules\Setup\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Setup\Library\Catalog;
use Modules\Setup\Library\Library;

/** SU3 (specs/setup/03): the ready-made library. Owners and staff. */
final class LibraryController
{
    public function index(Request $request, Library $library): Response
    {
        self::manager($request);

        return Inertia::render('setup/library', ['items' => $library->items(), 'kinds' => Catalog::KINDS]);
    }

    public function install(Request $request, string $item, Library $library): RedirectResponse
    {
        $user = self::manager($request);
        $copy = $request->boolean('copy');
        Inertia::flash('toast', ['type' => 'success', 'message' => $library->install($item, $user, $copy)]);

        return back();
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true), 403);

        return $user;
    }
}
