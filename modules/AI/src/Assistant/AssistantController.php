<?php

declare(strict_types=1);

namespace Modules\AI\Assistant;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Assistant\Handoffs;
use Modules\Core\Audit\AuditLogger;
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

    /** AS1: the people this person can hand the conversation to (their broker, RightAlly support). */
    public function targets(Request $request, Handoffs $handoffs): JsonResponse
    {
        return response()->json(['targets' => $handoffs->for(self::user($request))]);
    }

    /**
     * AS1: hand the conversation to a person, with what was asked and answered so they don't have to ask again.
     * Only this person's own conversation is passed on, and only its questions and answers (never hidden data).
     */
    public function handoff(Request $request, Handoffs $handoffs): JsonResponse
    {
        $user = self::user($request);
        $data = $request->validate(['target' => ['required', 'string', 'max:20'], 'conversation' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:1000']]);
        $turns = AssistantTurn::query()->where('user_id', $user->id)->where('conversation', $data['conversation'])->whereIn('role', ['person', 'assistant'])
            ->orderByDesc('id')->limit(12)->get()->reverse()->values();
        abort_if($turns->isEmpty(), 404);
        $first = (string) ($turns->firstWhere('role', 'person')->body ?? 'A question');
        $text = $turns->map(fn (AssistantTurn $t): string => ($t->role === 'person' ? $user->first_name : 'Your AI Assistant').': '.mb_substr((string) $t->body, 0, 1500))->implode("\n\n");
        if (trim((string) ($data['note'] ?? '')) !== '') {
            $text = trim((string) $data['note'])."\n\n".$text;
        }
        $done = $handoffs->send($data['target'], $user, mb_strimwidth(trim($first), 0, 140, '…'), $text);
        abort_if($done === null, 403);
        app(AuditLogger::class)->record('ai.assistant.handoff', metadata: ['target' => $data['target'], 'conversation' => $data['conversation']]);

        return response()->json(['message' => $done]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive() && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
