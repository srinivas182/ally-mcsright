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
        'keep-in-touch' => ['Keep in touch', ['rhythm_due' => true]],
        'birthdays-week' => ['Birthdays this week', ['birthday_within_days' => 7]],
        'anniversaries-week' => ['Home anniversaries this week', ['anniversary_within_days' => 7]],
    ];

    /** Keep-in-touch cadence in days by A/B/C/D ranking. */
    public const RHYTHM_DAYS = ['A' => 30, 'B' => 60, 'C' => 90, 'D' => 180];

    private const KEYS = ['lifecycle', 'origin', 'source', 'stage', 'tags', 'no_contact_days', 'overdue_task', 'created_within_days', 'cities', 'pipeline', 'keywords', 'rhythm', 'rhythm_due', 'birthday_within_days', 'anniversary_within_days'];

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
            ->when(! empty($filters['cities']) || ! empty($filters['pipeline']) || ! empty($filters['keywords']), fn ($q) => app(AskDatabase::class)->apply($q,
                array_intersect_key($filters, array_flip(['cities', 'pipeline', 'keywords']))))
            ->when(! empty($filters['rhythm']), fn ($q) => $q->whereIn('rhythm', (array) $filters['rhythm']))
            ->when(($filters['rhythm_due'] ?? false) === true, fn ($q) => $q->where(function ($q) use ($now): void {
                foreach (self::RHYTHM_DAYS as $tier => $days) {
                    $q->orWhere(fn ($t) => $t->where('rhythm', $tier)->where(fn ($d) => $d->whereNull('last_activity_at')
                        ->orWhere('last_activity_at', '<', $now->subDays($days))));
                }
            }))
            ->when(! empty($filters['birthday_within_days']), fn ($q) => self::monthDayWithin($q, 'birthday_md', $now, (int) $filters['birthday_within_days']))
            ->when(! empty($filters['anniversary_within_days']), fn ($q) => self::monthDayWithin($q, 'closed_md', $now, (int) $filters['anniversary_within_days']))
            ->when(($filters['overdue_task'] ?? false) === true, fn ($q) => $q->whereExists(fn ($t) => $t->selectRaw('1')->from('crm_tasks')
                ->whereColumn('crm_tasks.contact_id', 'crm_contacts.id')->whereNull('crm_tasks.completed_at')->where('crm_tasks.due_at', '<', $now)));
    }

    /**
     * Dates whose month and day fall within the next N days (wrapping into January).
     *
     * @param  Builder<Contact>  $query
     */
    private static function monthDayWithin(Builder $query, string $column, CarbonImmutable $now, int $days): void
    {
        $start = $now->month * 100 + $now->day;
        $endDate = $now->addDays(max(0, $days - 1));
        $end = $endDate->month * 100 + $endDate->day;

        $start <= $end
            ? $query->whereBetween($column, [$start, $end])
            : $query->where(fn ($q) => $q->where($column, '>=', $start)->orWhere($column, '<=', $end));
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
