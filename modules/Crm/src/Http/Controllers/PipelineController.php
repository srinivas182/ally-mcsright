<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Tags;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\SmartList;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Tag;
use Modules\Crm\SmartLists;

/**
 * Board view, stage moves, tags, smart lists and stage management.
 */
final class PipelineController
{
    public function __construct(
        private readonly ContactVisibility $visibility,
        private readonly Pipelines $pipelines,
    ) {}

    public function board(Request $request): Response
    {
        $user = self::user($request);
        $all = $this->pipelines->all();
        $current = $all->firstWhere('ulid', (string) $request->query('pipeline')) ?? $all->first();
        abort_if($current === null, 404);

        $stages = $current->stages->map(function (Stage $stage) use ($user): array {
            $query = $this->visibility->query($user)->where('stage_id', $stage->id);

            return [
                'ulid' => $stage->ulid, 'name' => $stage->name, 'outcome' => $stage->outcome,
                'count' => (clone $query)->count(),
                'contacts' => $query->orderByDesc('stage_changed_at')->limit(50)->get()->map(fn (Contact $c): array => [
                    'ulid' => $c->ulid, 'name' => $c->name, 'phone' => $c->primary_phone, 'origin' => $c->origin->value,
                    'days_in_stage' => $c->stage_changed_at !== null ? (int) $c->stage_changed_at->diffInDays(now()) : null,
                ]),
            ];
        });

        return Inertia::render('crm/contacts/board', [
            'pipelines' => $all->map(fn (Pipeline $p): array => ['ulid' => $p->ulid, 'name' => $p->name])->values(),
            'pipeline' => ['ulid' => $current->ulid, 'name' => $current->name],
            'stages' => $stages,
            'canManage' => CrmAccess::manager($user),
        ]);
    }

    public function move(Request $request, Contact $contact): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate(['stage' => ['required', 'string', 'size:26']]);
        $stage = Stage::query()->where('ulid', $data['stage'])->firstOrFail();

        $this->pipelines->move($contact, $stage, $user);

        return back();
    }

    public function tag(Request $request, Contact $contact, Tags $tags): RedirectResponse
    {
        abort_unless($this->visibility->canSee(self::user($request), $contact), 404);
        $data = $request->validate(['tag' => ['required', 'string', 'max:40']]);
        $tags->attach($contact, $data['tag']);

        return back();
    }

    public function untag(Request $request, Contact $contact, Tag $tag, Tags $tags): RedirectResponse
    {
        abort_unless($this->visibility->canSee(self::user($request), $contact), 404);
        $tags->detach($contact, $tag);

        return back();
    }

    public function saveList(Request $request, SmartLists $lists): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'filters' => ['required', 'array'], 'shared' => ['sometimes', 'boolean']]);
        $list = $lists->save(self::user($request), $data['name'], $data['filters'], (bool) ($data['shared'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => "List \"{$list->name}\" saved."]);

        return to_route('crm.contacts.index', ['list' => $list->ulid]);
    }

    public function deleteList(Request $request, SmartList $list, SmartLists $lists): RedirectResponse
    {
        $lists->delete($list, self::user($request));

        return to_route('crm.contacts.index');
    }

    public function addStage(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $this->pipelines->addStage($pipeline, $data['name'], self::user($request));

        return back();
    }

    public function renameStage(Request $request, Stage $stage): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $this->pipelines->rename($stage, $data['name'], self::user($request));

        return back();
    }

    public function reorder(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $data = $request->validate(['stages' => ['required', 'array'], 'stages.*' => ['string', 'size:26']]);
        $this->pipelines->reorder($pipeline, array_values($data['stages']), self::user($request));

        return back();
    }

    public function deleteStage(Request $request, Stage $stage): RedirectResponse
    {
        $data = $request->validate(['move_to' => ['required', 'string', 'size:26']]);
        $this->pipelines->delete($stage, Stage::query()->where('ulid', $data['move_to'])->firstOrFail(), self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
