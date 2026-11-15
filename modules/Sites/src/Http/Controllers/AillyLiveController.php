<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Sites\Ailly\LiveChat;

/**
 * Live AIlly chats for the team (specs/websites/14): the chats happening now, one chat's screen (it refreshes
 * every few seconds), taking over, replying and handing back to AIlly. Owners and staff see every chat; an agent
 * sees chats on their website and their own leads' chats.
 */
final class AillyLiveController
{
    public function __construct(private readonly LiveChat $live) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('ailly/chats', ['live' => $this->live->live($user)]);
    }

    public function show(Request $request, string $chat): Response
    {
        $user = self::user($request);

        return Inertia::render('ailly/chat', ['chat' => $this->live->view($this->live->find($chat, $user), $user)]);
    }

    public function take(Request $request, string $chat): RedirectResponse
    {
        $user = self::user($request);
        $this->live->takeOver($this->live->find($chat, $user), $user);

        return back();
    }

    public function say(Request $request, string $chat): RedirectResponse
    {
        $user = self::user($request);
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:1000']])['body'];
        $this->live->say($this->live->find($chat, $user), $user, $body);

        return back();
    }

    public function handBack(Request $request, string $chat): RedirectResponse
    {
        $user = self::user($request);
        $this->live->handBack($this->live->find($chat, $user), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'AIlly is answering again.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
