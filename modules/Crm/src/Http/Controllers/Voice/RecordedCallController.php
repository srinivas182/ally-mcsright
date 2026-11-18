<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Voice;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Modules\Crm\Models\Contact;
use Modules\Crm\Voice\RecordedCalls;

/**
 * Recorded calls with automatic notes (VO1 / D08): start one from the contact page; the phone provider's steps
 * (signed, 3-hour links; no session); try again or delete when writing down failed.
 */
final class RecordedCallController
{
    public function start(Request $request, RecordedCalls $calls, Contact $contact): RedirectResponse
    {
        $calls->start($contact, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Calling your phone now. Answer it, and we connect you. Notes arrive after the call.']);

        return back();
    }

    public function retry(Request $request, RecordedCalls $calls, Contact $contact, string $call): RedirectResponse
    {
        $calls->retry($call, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trying again. The notes arrive in a few minutes.']);

        return back();
    }

    public function discard(Request $request, RecordedCalls $calls, Contact $contact, string $call): RedirectResponse
    {
        $calls->discard($call, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The recording was deleted.']);

        return back();
    }

    public function step(Request $request, RecordedCalls $calls, string $call, string $step): Response
    {
        abort_unless($request->hasValidSignature(false), 403);

        return response($calls->twiml($call, $step, $request->only(['RecordingSid', 'RecordingDuration', 'RecordingStatus'])), 200, ['Content-Type' => 'text/xml']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
