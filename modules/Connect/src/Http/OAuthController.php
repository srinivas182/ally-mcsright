<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Connect\Connections;
use Modules\Connect\OAuth\OAuthFlow;
use Modules\Core\Identity\Enums\UserKind;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** One-click connect (specs/connect/02): start on the brokerage host, the provider's answer on the central host, finish back home. */
final class OAuthController
{
    public function __construct(private readonly OAuthFlow $flow, private readonly Connections $connections) {}

    public function start(Request $request, string $app): Response
    {
        $user = self::user($request);
        try {
            return Inertia::location($this->flow->start($this->connections->app($app), $user, $request));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    /** Central host: the provider sends the person back here. */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $state = OAuthFlow::state((string) $request->query('state', ''));
            $app = $this->connections->app($state['a']);

            return redirect()->away($this->flow->callback($app, $request->query(), $state));
        } catch (Throwable) {
            abort(400, 'Connecting did not finish. Please go back to the Apps page and press Connect again.');
        }
    }

    public function complete(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $app = $this->connections->app((string) $request->query('app', ''));
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect('/apps/'.$app->key)->withErrors(['app' => $error === 'cancelled'
                ? 'You didn\'t approve the connection, so nothing changed. Press Connect to try again.'
                : $app->name.' didn\'t finish connecting. Please press Connect again.']);
        }
        try {
            $c = $this->flow->complete($app, (string) $request->query('ticket', ''), $user, $request);
        } catch (ValidationException $e) {
            return redirect('/apps/'.$app->key)->withErrors($e->errors());
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $app->name.' is connected'.($c->label !== null && $c->label !== $app->name ? ' ('.$c->label.')' : '').'.']);

        return redirect((string) $request->session()->pull('connect.return', '/apps/'.$app->key));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
