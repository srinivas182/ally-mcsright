<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Team\Chat;
use Modules\Team\Models\Channel;
use Modules\Team\Models\Message;

/** Team chat pages (CH1, specs/team/01). New messages are fetched every 10 seconds while the page is open. */
final class ChatController
{
    public function index(Request $request, Chat $chat): Response
    {
        $user = self::user($request);
        $channels = $chat->channelsFor($user);
        $open = (string) $request->query('c', '');
        $current = collect($channels)->firstWhere('ulid', $open) ?? $channels[0] ?? null;
        $channel = $current !== null ? Channel::query()->where('ulid', $current['ulid'])->first() : null;

        return Inertia::render('team/chat', [
            'channels' => $channels,
            'current' => $current,
            'messages' => $channel !== null ? $chat->messages($channel, $user) : [],
            'people' => Chat::people()->where('id', '!=', $user->id)->orderBy('first_name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->name])->values(),
            'members' => $channel !== null ? $chat->memberIds($channel) : [],
            'leads' => Chat::leads($user),
        ]);
    }

    public function poll(Request $request, Channel $channel, Chat $chat): JsonResponse
    {
        $user = self::user($request);

        return response()->json(['messages' => $chat->messages($channel, $user, (int) $request->query('after', '0')), 'unread' => $chat->unreadTotal($user)]);
    }

    public function post(Request $request, Channel $channel, Chat $chat): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Chat::MAX_LENGTH], 'mentions' => ['sometimes', 'array', 'max:50'], 'mentions.*' => ['integer']]);
        $chat->post($channel, $data['body'], self::user($request), array_values($data['mentions'] ?? []));

        return redirect('/chat?c='.$channel->ulid);
    }

    public function edit(Request $request, int $message, Chat $chat): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Chat::MAX_LENGTH]]);
        $chat->edit(Message::query()->whereKey($message)->firstOrFail(), $data['body'], self::user($request));

        return back();
    }

    public function hide(Request $request, int $message, Chat $chat): RedirectResponse
    {
        $m = Message::query()->whereKey($message)->firstOrFail();
        $user = self::user($request);
        abort_unless($chat->canSee($user, Channel::query()->whereKey($m->channel_id)->firstOrFail()), 403);
        $chat->hide($m, $user);

        return back();
    }

    public function direct(Request $request, Chat $chat): RedirectResponse
    {
        $data = $request->validate(['user' => ['required', 'integer']]);
        $other = User::query()->whereKey((int) $data['user'])->firstOrFail();

        return redirect('/chat?c='.$chat->direct(self::user($request), $other)->ulid);
    }

    public function group(Request $request, Chat $chat): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'members' => ['required', 'array', 'min:1', 'max:500'], 'members.*' => ['integer']]);

        return redirect('/chat?c='.$chat->group($data['name'], array_values($data['members']), self::user($request))->ulid);
    }

    public function members(Request $request, Channel $channel, Chat $chat): RedirectResponse
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:500'], 'members.*' => ['integer']]);
        $chat->setMembers($channel, array_values($data['members']), self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && Chat::allowed($user), 403);

        return $user;
    }
}
