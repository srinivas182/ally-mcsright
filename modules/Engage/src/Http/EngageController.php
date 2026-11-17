<?php

declare(strict_types=1);

namespace Modules\Engage\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Engage\DatabaseAssistant;
use Modules\Engage\Engagement;
use Modules\Engage\GamePlan;
use Modules\Engage\Models\CheckIn;
use Modules\Engage\Models\Recognition;
use Modules\Engage\Recognitions;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Engage (EN1): the agent's game plan, coach and database assistant; the broker's agent activity and recognitions. */
final class EngageController
{
    public function index(Request $request, GamePlan $plan, DatabaseAssistant $assistant): Response
    {
        $user = self::user($request);
        $checkins = CheckIn::query()->where('user_id', $user->id)->whereIn('status', ['draft', 'replied'])->orderByDesc('id')->limit(40)->get();
        $names = Contact::query()->whereIn('id', $checkins->pluck('contact_id')->unique())->get()->mapWithKeys(fn (Contact $c): array => [$c->id => $c->name]);

        return Inertia::render('engage/index', [
            'plan' => $plan->for($user),
            'assistant' => $assistant->settings($user),
            'checkins' => $checkins->map(fn (CheckIn $c): array => ['ulid' => $c->ulid, 'to' => (string) ($names[$c->contact_id] ?? ''), 'status' => $c->status,
                'subject' => $c->subject, 'body' => $c->body, 'intent' => $c->intent !== null ? DatabaseAssistant::INTENTS[$c->intent] ?? $c->intent : null, 'brief' => $c->brief,
                'suggested_reply' => $c->suggested_reply, 'conversation' => Conversation::query()->where('contact_id', $c->contact_id)->value('ulid')])->values(),
            'recognitions' => Recognition::query()->where('to_user_id', $user->id)->whereNotNull('sent_at')->orderByDesc('id')->limit(10)->get()
                ->map(fn (Recognition $r): array => ['ulid' => $r->ulid, 'occasion' => Recognitions::OCCASIONS[$r->occasion] ?? $r->occasion, 'text' => $r->text,
                    'audio' => $r->audio_kind, 'at' => $r->created_at->toDateString()])->values(),
            'focus' => (string) $request->query('checkin', ''),
        ]);
    }

    public function coach(Request $request, GamePlan $plan): RedirectResponse
    {
        try {
            $plan->coach(self::user($request));
        } catch (InsufficientCredits) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Your brokerage is out of RightAlly credits for the coach today.']);
        }

        return back();
    }

    public function saveAssistant(Request $request, DatabaseAssistant $assistant): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'weekly' => ['required', 'integer', 'min:1', 'max:20'], 'audience' => ['required', 'string'],
            'about' => ['nullable', 'string', 'max:300']]);
        $assistant->save(self::user($request), (bool) $data['active'], (int) $data['weekly'], $data['audience'], $data['about'] ?? null);

        return back();
    }

    public function draftNow(Request $request, DatabaseAssistant $assistant): RedirectResponse
    {
        $n = $assistant->draftWeek(self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $n > 0 ? "{$n} check-in(s) drafted. Read and send the ones you like." : 'Nobody is due a check-in right now.']);

        return back();
    }

    public function send(Request $request, string $checkin, DatabaseAssistant $assistant): RedirectResponse
    {
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:5000']]);
        $assistant->send(self::checkin($checkin), self::user($request), $data['subject'] ?? null, $data['body'] ?? null);

        return back();
    }

    public function skip(Request $request, string $checkin, DatabaseAssistant $assistant): RedirectResponse
    {
        $assistant->skip(self::checkin($checkin), self::user($request));

        return back();
    }

    public function activity(Request $request, Engagement $engagement, Recognitions $recognitions): Response
    {
        $user = self::user($request);
        abort_unless(Recognitions::leads($user), 403);

        return Inertia::render('engage/activity', ['agents' => $engagement->forBroker(), 'milestones' => $recognitions->milestones(),
            'occasions' => Recognitions::OCCASIONS, 'voice' => $recognitions->voice($user) !== null, 'consent' => $recognitions->consentText($user),
            'full_name' => trim($user->first_name.' '.$user->last_name)]);
    }

    public function recognise(Request $request, Recognitions $recognitions): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'integer'], 'occasion' => ['required', 'string'], 'text' => ['required', 'string', 'max:600'],
            'recording' => ['nullable', 'file', 'max:5120'], 'ai_voice' => ['sometimes', 'boolean']]);
        $agent = User::query()->whereKey((int) $data['agent'])->where('kind', UserKind::Agent->value)->firstOrFail();
        $recognitions->send(self::user($request), $agent, $data['occasion'], $data['text'], $request->file('recording'), (bool) ($data['ai_voice'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent. Thank you for taking the time.']);

        return back();
    }

    public function voice(Request $request, Recognitions $recognitions): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'agree' => ['required', 'boolean'], 'recording' => ['required', 'file', 'max:25600']]);
        $file = $request->file('recording');
        abort_unless($file instanceof UploadedFile, 422);
        $recognitions->consent(self::user($request), $data['name'], (bool) $data['agree'], $file, $request->ip());

        return back();
    }

    public function deleteVoice(Request $request, Recognitions $recognitions): RedirectResponse
    {
        $recognitions->withdraw(self::user($request));

        return back();
    }

    public function recognition(Request $request, string $recognition): Response
    {
        $r = self::recognitionFor($request, $recognition);
        if ($r->played_at === null && $r->to_user_id === self::user($request)->id) {
            $r->update(['played_at' => now()]);
        }

        return Inertia::render('engage/recognition', ['recognition' => ['ulid' => $r->ulid, 'from' => (string) User::query()->whereKey($r->from_user_id)->value('first_name'),
            'occasion' => Recognitions::OCCASIONS[$r->occasion] ?? $r->occasion, 'text' => $r->text, 'audio' => $r->audio_kind, 'at' => $r->created_at->toDateString()]]);
    }

    public function audio(Request $request, string $recognition): HttpResponse
    {
        $r = self::recognitionFor($request, $recognition);
        abort_if($r->audio_path === null || ! Storage::disk('local')->exists($r->audio_path), 404);

        return response((string) Storage::disk('local')->get($r->audio_path), 200, ['Content-Type' => str_ends_with($r->audio_path, '.mp3') ? 'audio/mpeg' : 'audio/webm',
            'Cache-Control' => 'private, no-store']);
    }

    private static function recognitionFor(Request $request, string $ulid): Recognition
    {
        $user = self::user($request);
        $r = Recognition::query()->where('ulid', $ulid)->first();
        abort_if($r === null || ($r->to_user_id !== $user->id && $r->from_user_id !== $user->id), 404);

        return $r;
    }

    private static function checkin(string $ulid): CheckIn
    {
        return CheckIn::query()->where('ulid', $ulid)->firstOrFail();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
