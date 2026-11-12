<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;

/**
 * The brokerage audit log (owners): filters by area, person, date and severity.
 */
final class AuditController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);

        $filters = $request->validate([
            'area' => ['nullable', 'in:core.auth,core.impersonation,core.user,crm.contact,crm.task,crm.consent'],
            'actor' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'severity' => ['nullable', 'in:notice,warning,critical'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $actorId = isset($filters['actor']) ? User::query()->where('ulid', $filters['actor'])->value('id') : null;
        $events = AuditEvent::query()
            ->when($filters['area'] ?? null, fn ($q, $area) => $q->where('action', 'like', $area.'.%'))
            ->when($actorId, fn ($q, $id) => $q->where(fn ($w) => $w->where('actor_id', $id)->orWhere('on_behalf_of_user_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', $d.' 23:59:59.999999'))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->whereIn('severity', array_slice(['notice', 'warning', 'critical'], array_search($s, ['notice', 'warning', 'critical'], true) ?: 0)))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where('subject_label', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%'))
            ->orderByDesc('chain_seq')
            ->paginate(50)->withQueryString()
            ->through(fn (AuditEvent $e): array => AuditPresenter::row($e));

        return Inertia::render('audit/index', [
            'events' => $events,
            'filters' => $filters + ['area' => null, 'actor' => null, 'from' => null, 'to' => null, 'severity' => null, 'q' => null],
            'people' => User::query()->orderBy('first_name')->get(['ulid', 'first_name', 'last_name'])->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
        ]);
    }
}
