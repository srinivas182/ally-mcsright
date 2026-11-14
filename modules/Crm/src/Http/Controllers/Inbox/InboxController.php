<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Inbox;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\SignCodes;
use Modules\Crm\Inbox\Templates;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\SignCode;
use Modules\Crm\Models\Template;

/** The unified inbox (specs/grow/05). Agents see conversations with contacts they can see. */
final class InboxController
{
    public function index(Request $request, ContactVisibility $visibility, Inbox $inbox, Templates $templates, SignCodes $signs): Response
    {
        $user = self::user($request);
        $filter = in_array($request->query('filter'), ['needs_reply', 'unread', 'mine', 'all'], true) ? (string) $request->query('filter') : 'all';
        $visible = $visibility->query($user)->select('crm_contacts.id');
        $list = Conversation::query()->whereIn('contact_id', $visible)->whereNotNull('last_message_at')->with('contact')
            ->when($filter === 'needs_reply', fn ($q) => $q->whereNotNull('needs_reply_since'))
            ->when($filter === 'unread', fn ($q) => $q->where('unread', true))
            ->when($filter === 'mine', fn ($q) => $q->where('assigned_user_id', $user->id))
            ->orderByDesc('last_message_at')->limit(60)->get();
        $names = User::query()->whereIn('id', $list->pluck('assigned_user_id')->filter()->unique())->get()->keyBy('id');

        $current = null;
        $ulid = (string) $request->query('c', '');
        $conversation = $ulid !== '' ? Conversation::query()->where('ulid', $ulid)->with('contact')->first() : null;
        if ($conversation !== null && $visibility->canSee($user, $conversation->contact)) {
            $inbox->markRead($conversation);
            $contact = $conversation->contact;
            $current = [
                'ulid' => $conversation->ulid,
                'contact' => ['ulid' => $contact->ulid, 'name' => $contact->name, 'phone' => $contact->primary_phone, 'email' => $contact->primary_email],
                'messages' => Message::query()->where('conversation_id', $conversation->id)->latest('id')->limit(100)->get()->reverse()->values()
                    ->map(fn (Message $m): array => ['ulid' => $m->ulid, 'channel' => $m->channel, 'direction' => $m->direction, 'subject' => $m->subject, 'body' => $m->body,
                        'status' => $m->status, 'at' => $m->created_at->toIso8601String(), 'ai' => $m->ai_drafted,
                        'by' => $m->user_id !== null ? User::query()->find($m->user_id)?->first_name : null]),
                // What stops a message right now (independent of what is written).
                'blocks' => ['sms' => $inbox->check($contact, 'sms', 'ok'), 'email' => $inbox->check($contact, 'email', 'ok')],
                'templates' => $templates->for($user)->map(fn (Template $t): array => ['ulid' => $t->ulid, 'name' => $t->name, 'channel' => $t->channel,
                    'subject' => $t->subject !== null ? $templates->render($t->subject, $contact, $user) : null, 'body' => $templates->render($t->body, $contact, $user)]),
            ];
        }

        return Inertia::render('crm/inbox/index', [
            'conversations' => $list->map(fn (Conversation $c): array => ['ulid' => $c->ulid, 'name' => $c->contact->name, 'preview' => $c->preview, 'at' => $c->last_message_at?->toIso8601String(),
                'unread' => $c->unread, 'needs_reply' => $c->needs_reply_since !== null, 'waiting_since' => $c->needs_reply_since?->toIso8601String(),
                'agent' => $c->assigned_user_id !== null ? $names->get($c->assigned_user_id)?->name : null]),
            'filter' => $filter,
            'current' => $current,
            'templates' => $templates->for($user)->map(fn (Template $t): array => ['ulid' => $t->ulid, 'name' => $t->name, 'channel' => $t->channel, 'subject' => $t->subject,
                'body' => $t->body, 'shared' => $t->user_id === null, 'mine' => $t->user_id === $user->id]),
            'fields' => Templates::FIELDS,
            'signCodes' => SignCode::query()->when(! CrmAccess::manager($user), fn ($q) => $q->where('user_id', $user->id))->orderBy('code')->get()
                ->map(fn (SignCode $s): array => ['ulid' => $s->ulid, 'code' => $s->code, 'property' => $s->property, 'details' => $s->details, 'active' => $s->active, 'texts' => $s->texts]),
            'signNumber' => $signs->number(),
            'canManage' => CrmAccess::manager($user),
        ]);
    }

    public function start(Request $request, Contact $contact, ContactVisibility $visibility, Inbox $inbox): RedirectResponse
    {
        abort_unless($visibility->canSee(self::user($request), $contact), 404);

        return redirect('/inbox?c='.$inbox->conversationFor($contact)->ulid);
    }

    public function send(Request $request, Conversation $conversation, ContactVisibility $visibility, Inbox $inbox): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $conversation->contact), 404);
        $data = $request->validate(['channel' => ['required', 'in:sms,email'], 'body' => ['required', 'string', 'max:20000'], 'subject' => ['nullable', 'string', 'max:200'], 'ai_drafted' => ['nullable', 'boolean']]);
        $inbox->send($conversation->contact, $data['channel'], $data['body'], $data['subject'] ?? null, $user, (bool) ($data['ai_drafted'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['channel'] === 'sms' ? 'Text sent.' : 'Email sent.']);

        return back();
    }

    public function draft(Request $request, Conversation $conversation, ContactVisibility $visibility, Inbox $inbox): JsonResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $conversation->contact), 404);
        $channel = $request->input('channel') === 'email' ? 'email' : 'sms';
        try {
            return response()->json($inbox->draft($conversation, $channel, $user));
        } catch (InsufficientCredits) {
            return response()->json(['message' => 'Out of AI credits. Ask your broker to top up.'], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The AI assistant is unavailable right now. Please write the reply yourself.'], 422);
        }
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless(CrmAccess::manager(self::user($request)), 403);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $agent = User::query()->whereKey($data['user_id'])->where('status', 'active')->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])->firstOrFail();
        $conversation->forceFill(['assigned_user_id' => $agent->id])->save();

        return back();
    }

    public function storeTemplate(Request $request, Templates $templates): RedirectResponse
    {
        $templates->save($request->only(['name', 'channel', 'subject', 'body', 'shared']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template saved.']);

        return back();
    }

    public function destroyTemplate(Request $request, Template $template, Templates $templates): RedirectResponse
    {
        $templates->delete($template, self::user($request));

        return back();
    }

    public function storeSign(Request $request, SignCodes $signs): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'property' => ['required', 'string', 'max:200'], 'details' => ['required', 'string', 'max:300']]);
        $signs->create($data['code'], $data['property'], $data['details'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sign code ready.']);

        return back();
    }

    public function toggleSign(Request $request, SignCode $sign): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($sign->user_id === $user->id || CrmAccess::manager($user), 403);
        $sign->forceFill(['active' => ! $sign->active])->save();

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
