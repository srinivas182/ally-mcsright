<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Team\Help\PageHelp;

/**
 * HQ4: the HQ console's own menu guides. The brokerage help centre is not open on the HQ host, so HQ staff get the
 * guide videos and the "seen" mark here. HQ adds a lesson video under Support › Help videos with the address
 * guide:hq-<menu>:<lesson> (for example guide:hq-customers:go-live).
 */
final class HqGuidesController
{
    public function videos(): JsonResponse
    {
        return response()->json(DB::connection(Connections::control())->table('help_videos')->where('path', 'like', 'guide:hq-%')->pluck('url', 'path')
            ->map(fn (mixed $url): string => PageHelp::embed((string) $url)));
    }

    /** A menu guide was closed; it no longer opens by itself for this person. */
    public function seen(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::HqStaff, 403);
        $key = mb_substr(trim((string) $request->input('key', '')), 0, 40);
        if (str_starts_with($key, 'HQ ')) {
            $seen = array_values(array_unique([...(array) ($user->ui_guides_seen ?? []), $key]));
            $user->forceFill(['ui_guides_seen' => array_slice($seen, -50)])->save();
        }

        return back();
    }
}
