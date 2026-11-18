<?php

declare(strict_types=1);

namespace Modules\Appointments\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Appointments\Recordings;

/** Recorded appointments (VO2, specs/listings/05): consent, audio pieces, stop, notes and coaching. */
final class RecordingsController
{
    public function start(Request $request, Recordings $recordings, string $consultation): JsonResponse
    {
        $data = $request->validate(['names' => ['required', 'string', 'max:300'], 'agreed' => ['accepted']],
            ['agreed.accepted' => 'Read the script aloud and confirm everyone agreed.']);

        return response()->json(['recording' => $recordings->start($consultation, (string) $data['names'], $request->ip(), self::user($request))]);
    }

    public function piece(Request $request, Recordings $recordings, string $recording): JsonResponse
    {
        $data = $request->validate(['seq' => ['required', 'integer', 'min:0', 'max:1000'], 'audio' => ['required', 'file', 'max:10240']]);
        $recordings->piece($recording, (int) $data['seq'], $request->file('audio'), self::user($request));

        return response()->json(['ok' => true]);
    }

    public function stop(Request $request, Recordings $recordings, string $recording): RedirectResponse
    {
        $recordings->stop($recording, (int) $request->integer('seconds'), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Your notes and coaching arrive in a few minutes.']);

        return back();
    }

    public function retry(Request $request, Recordings $recordings, string $recording): RedirectResponse
    {
        $recordings->retry($recording, self::user($request));

        return back();
    }

    public function share(Request $request, Recordings $recordings, string $recording): RedirectResponse
    {
        $recordings->share($recording, $request->boolean('shared'), self::user($request));

        return back();
    }

    public function discard(Request $request, Recordings $recordings, string $recording): RedirectResponse
    {
        $recordings->discard($recording, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The recording and its notes were deleted.']);

        return back();
    }

    public function coaching(Request $request, Recordings $recordings): Response
    {
        return Inertia::render('appointments/coaching', ['trends' => $recordings->trends(self::user($request))]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
