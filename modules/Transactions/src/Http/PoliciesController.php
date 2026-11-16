<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Transactions\Policies\PolicyAnswers;
use Modules\Transactions\Policies\PolicyLibrary;

/** Compliance Knowledge (specs/ai/05, DQ6): ask, the library, acknowledgements, and the broker's replies and gaps. */
final class PoliciesController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $broker = self::broker($user);

        return Inertia::render('policies/index', [
            'broker' => $broker,
            'toAcknowledge' => PolicyLibrary::toAcknowledge($user),
            'mine' => PolicyAnswers::questions($user),
            'documents' => $broker ? PolicyLibrary::documents() : array_map(fn (array $d): array => ['ulid' => $d['ulid'], 'title' => $d['title'], 'version' => $d['version'], 'pages' => $d['pages'],
                'summary' => $d['summary'], 'at' => $d['at'], 'acknowledged' => 0, 'waiting' => []], PolicyLibrary::documents()),
            'gaps' => $broker ? PolicyAnswers::questions(null, true) : [],
        ]);
    }

    public function ask(Request $request, PolicyAnswers $answers): JsonResponse
    {
        $user = self::user($request);

        return response()->json($answers->ask($user, (string) $request->validate(['question' => ['required', 'string', 'max:1000']])['question']));
    }

    public function add(Request $request, PolicyLibrary $library): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::broker($user), 403);
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'file' => ['nullable', 'file', 'max:25600', 'mimes:pdf'], 'text' => ['nullable', 'string', 'max:200000'],
            'replaces' => ['nullable', 'string', 'size:26']]);
        $library->add($d['title'], $request->file('file'), $d['text'] ?? null, $d['replaces'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Published. Agents have been sent what changed to acknowledge.']);

        return back();
    }

    public function remove(Request $request, string $doc, PolicyLibrary $library): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::broker($user), 403);
        $library->remove($doc, $user);

        return back();
    }

    public function acknowledge(Request $request, string $doc, PolicyLibrary $library): RedirectResponse
    {
        $library->acknowledge($doc, self::user($request));

        return back();
    }

    public function reply(Request $request, string $question, PolicyAnswers $answers): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::broker($user), 403);
        $answers->reply($question, (string) $request->validate(['reply' => ['required', 'string', 'max:3000']])['reply'], $user);

        return back();
    }

    public function closeGap(Request $request, string $question, PolicyAnswers $answers): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::broker($user), 403);
        $answers->closeGap($question, $user);

        return back();
    }

    private static function broker(User $user): bool
    {
        return Can::manage($user, 'transactions.templates') || Can::manage($user, 'transactions.review');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
