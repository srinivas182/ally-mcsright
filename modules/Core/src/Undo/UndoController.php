<?php

declare(strict_types=1);

namespace Modules\Core\Undo;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class UndoController
{
    public function __invoke(Request $request, string $undo, Undo $undos): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $label = $undos->run($undo, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Undone: '.$label.'.']);

        return back();
    }
}
