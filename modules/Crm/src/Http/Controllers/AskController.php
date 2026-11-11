<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\AiGateway;
use Modules\AI\Exceptions\AiOutputBlocked;
use Modules\AI\Exceptions\AllModelsFailed;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Exceptions\OutputSchemaViolation;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\UnknownFeature;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\AskDatabase;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\VoiceUpdates;
use Throwable;

/**
 * Ask your database (typed or spoken), speech to text, and voice/text updates with preview.
 */
final class AskController
{
    private const AI_ERRORS = [UnknownFeature::class, AllModelsFailed::class, InsufficientCredits::class, AiOutputBlocked::class, OutputSchemaViolation::class, ProviderRejected::class];

    public function ask(Request $request, AskDatabase $ask): Response
    {
        $user = self::user($request);
        $question = trim((string) $request->query('q', ''));
        $props = ['q' => $question, 'explanation' => null, 'filters' => null, 'results' => [], 'error' => null];

        if ($question !== '') {
            try {
                $understood = $ask->understand($question, $user);
                $props['explanation'] = $understood['explanation'];
                $props['filters'] = $understood['filters'];
                $props['results'] = $ask->search($understood['filters'], $user);
            } catch (Throwable $e) {
                $props['error'] = self::friendly($e);
            }
        }

        return Inertia::render('crm/contacts/ask', $props);
    }

    /**
     * Speech to text. The recording is used once and discarded.
     */
    public function transcribe(Request $request, AiGateway $gateway): JsonResponse
    {
        $user = self::user($request);
        $request->validate(['audio' => ['required', 'file', 'max:25600', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/x-wav,video/webm']]);
        $file = $request->file('audio');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $text = $gateway->transcribe(VoiceUpdates::TRANSCRIBE, (string) $file->get(), (string) $file->getMimeType(),
                'crm-voice:'.$user->id.':'.now()->format('YmdHisv'), $user->id, $user->locale === 'es' ? 'es' : 'en');
        } catch (Throwable $e) {
            return response()->json(['message' => self::friendly($e)], 422);
        } finally {
            @unlink($file->getRealPath());
        }

        return response()->json(['text' => $text]);
    }

    public function proposeLog(Request $request, Contact $contact, VoiceUpdates $voice, ContactVisibility $visibility): JsonResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $contact), 404);
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);

        try {
            return response()->json(['proposal' => $voice->propose($contact, $data['text'], $user)]);
        } catch (Throwable $e) {
            return response()->json(['message' => self::friendly($e)], 422);
        }
    }

    public function applyLog(Request $request, Contact $contact, VoiceUpdates $voice, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $contact), 404);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'], 'call_outcome' => ['nullable', 'string'], 'lifecycle' => ['nullable', 'string'],
            'tags' => ['nullable', 'array', 'max:5'], 'tags.*' => ['string', 'max:40'], 'task_title' => ['nullable', 'string', 'max:200'],
            'task_due' => ['nullable', 'string'],
        ]);

        $voice->apply($contact, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Update saved.']);

        return back();
    }

    public function parseQuickAdd(Request $request, VoiceUpdates $voice): JsonResponse
    {
        $user = self::user($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);

        try {
            return response()->json(['contact' => $voice->parseQuickAdd($data['text'], $user)]);
        } catch (Throwable $e) {
            return response()->json(['message' => self::friendly($e)], 422);
        }
    }

    private static function friendly(Throwable $e): string
    {
        foreach (self::AI_ERRORS as $class) {
            if ($e instanceof $class) {
                return $e instanceof InsufficientCredits ? 'Out of AI credits. Ask your broker to top up.' : 'The AI assistant is unavailable right now. Please try again or type it in.';
            }
        }

        if ($e instanceof \InvalidArgumentException) {
            return 'The recording was empty or too long. Please try again.';
        }

        throw $e;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
