<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\SmartList;
use Modules\Crm\Models\Stage;

/**
 * Saved filters that stay current, plus ready-made daily lists. Filters always run on top of
 * ContactVisibility, so a shared list never shows anyone contacts they may not see.
 */
final class SmartLists
{
    public const READY_MADE = [
        'new-leads' => ['New leads', ['lifecycle' => ['lead'], 'created_within_days' => 7]],
        'no-contact-7' => ['No contact in 7 days', ['lifecycle' => ['lead', 'active'], 'no_contact_days' => 7]],
        'overdue-tasks' => ['Overdue tasks', ['overdue_task' => true]],
    ];

    private const KEYS = ['lifecycle', 'origin', 'source', 'stage', 'tags', 'no_contact_days', 'overdue_task', 'created_within_days'];

    public function __construct(private readonly ContactVisibility $visibility) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function validate(array $filters): array
    {
        $errors = [];
        foreach (array_diff(array_keys($filters), self::KEYS) as $unknown) {
            $errors["filters.{$unknown}"] = 'Unknown filter.';
        }
        foreach ((array) ($filters['lifecycle'] ?? []) as $value) {
            if (Lifecycle::tryFrom((string) $value) === null) {
                $errors['filters.lifecycle'] = 'Unknown stage.';
            }
        }
        foreach ((array) ($filters['source'] ?? []) as $value) {
            if (Source::tryFrom((string) $value) === null) {
                $errors['filters.source'] = 'Unknown source.';
            }
        }
        if (isset($filters['origin']) && Origin::tryFrom((string) $filters['origin']) === null) {
            $errors['filters.origin'] = 'Unknown origin.';
        }
        foreach (['no_contact_days', 'created_within_days'] as $days) {
            if (isset($filters[$days]) && (! is_int($filters[$days]) || $filters[$days] < 1 || $filters[$days] > 3650)) {
                $errors["filters.{$days}"] = 'Use a number of days from 1 to 3650.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $filters;
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Contact>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $now = CarbonImmutable::now();

        return $query
            ->when(! empty($filters['lifecycle']), fn ($q) => $q->whereIn('lifecycle', (array) $filters['lifecycle']))
            ->when(! empty($filters['origin']), fn ($q) => $q->where('origin', $filters['origin']))
            ->when(! empty($filters['source']), fn ($q) => $q->whereIn('source', (array) $filters['source']))
            ->when(! empty($filters['stage']), fn ($q) => $q->where('stage_id', Stage::query()->where('ulid', $filters['stage'])->value('id') ?? 0))
            ->when(! empty($filters['tags']), fn ($q) => $q->whereHas('tags', fn ($t) => $t->whereIn('name', (array) $filters['tags'])))
            ->when(! empty($filters['no_contact_days']), fn ($q) => $q->where(fn ($q) => $q->whereNull('last_activity_at')
                ->orWhere('last_activity_at', '<', $now->subDays((int) $filters['no_contact_days']))))
            ->when(! empty($filters['created_within_days']), fn ($q) => $q->where('created_at', '>=', $now->subDays((int) $filters['created_within_days'])))
            ->when(($filters['overdue_task'] ?? false) === true, fn ($q) => $q->whereExists(fn ($t) => $t->selectRaw('1')->from('crm_tasks')
                ->whereColumn('crm_tasks.contact_id', 'crm_contacts.id')->whereNull('crm_tasks.completed_at')->where('crm_tasks.due_at', '<', $now)));
    }

    /**
     * Ready-made lists first, then the user's own and the brokerage's shared lists, with counts.
     *
     * @return list<array{key: string, name: string, count: int, ready_made: bool, shared: bool, mine: bool}>
     */
    public function listsFor(User $user): array
    {
        $out = [];
        foreach (self::READY_MADE as $key => [$name, $filters]) {
            $out[] = ['key' => $key, 'name' => $name, 'count' => $this->apply($this->visibility->query($user), $filters)->count(), 'ready_made' => true, 'shared' => false, 'mine' => false];
        }

        $saved = SmartList::query()->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhere('shared', true))->orderBy('sort')->orderBy('name')->get();
        foreach ($saved as $list) {
            $out[] = ['key' => $list->ulid, 'name' => $list->name, 'count' => $this->apply($this->visibility->query($user), $list->filters)->count(),
                'ready_made' => false, 'shared' => $list->shared, 'mine' => $list->owner_user_id === $user->id];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null filters for a ready-made key or a list the user may use
     */
    public function filtersFor(string $key, User $user): ?array
    {
        if (isset(self::READY_MADE[$key])) {
            return self::READY_MADE[$key][1];
        }

        $list = SmartList::query()->where('ulid', $key)->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhere('shared', true))->first();

        return $list?->filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function save(User $user, string $name, array $filters, bool $shared): SmartList
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Give the list a short name.']);
        }
        if ($shared && ! $user->isKind(UserKind::Owner, UserKind::Staff)) {
            throw ValidationException::withMessages(['shared' => 'Only owners and staff can share lists with the brokerage.']);
        }

        return SmartList::query()->create(['owner_user_id' => $user->id, 'name' => $name, 'filters' => $this->validate($filters), 'shared' => $shared]);
    }

    public function delete(SmartList $list, User $user): void
    {
        $mayDelete = $list->owner_user_id === $user->id || ($list->shared && $user->isKind(UserKind::Owner, UserKind::Staff));
        if (! $mayDelete) {
            throw ValidationException::withMessages(['list' => 'You can only delete your own lists.']);
        }
        $list->delete();
    }
}
