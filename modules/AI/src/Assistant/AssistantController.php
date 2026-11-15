<?php

declare(strict_types=1);

namespace Modules\AI\Assistant;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Identity\Enums\UserKind;

/** The back-office chat for "Your AI Assistant" (specs/know/03): JSON for the bottom-right panel. */
final class AssistantController
{
    public function latest(Request $request, YourAiAssistant $assistant): JsonResponse
    {
        return response()->json($assistant->latest(self::user($request)));
    }

    public function ask(Request $request, YourAiAssistant $assistant): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'conversation' => ['nullable', 'string', 'size:26'], 'path' => ['nullable', 'string', 'max:300'],
            'title' => ['nullable', 'string', 'max:200']]);

        return response()->json($assistant->ask(self::user($request), $data['conversation'] ?? null, $data['text'], ['path' => $data['path'] ?? '', 'title' => $data['title'] ?? '']));
    }

    public function doIt(Request $request, string $turn, YourAiAssistant $assistant): JsonResponse
    {
        $user = self::user($request);

        return response()->json($assistant->doIt($user, AssistantTurn::query()->where('ulid', $turn)->where('user_id', $user->id)->firstOrFail()));
    }

    public function cancel(Request $request, string $turn, YourAiAssistant $assistant): JsonResponse
    {
        $user = self::user($request);
        $assistant->cancel($user, AssistantTurn::query()->where('ulid', $turn)->where('user_id', $user->id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive() && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
