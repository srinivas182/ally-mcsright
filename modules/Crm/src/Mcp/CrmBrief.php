<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Mcp\McpToolbox;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Crm\TodaysTen;

/** The CRM's sections of the daily brief (specs/connect/03): same visibility rules as the screens. */
final class CrmBrief
{
    public static function register(McpToolbox $toolbox): void
    {
        $toolbox->brief('call_today', self::callToday(...));
        $toolbox->brief('tasks', self::tasks(...));
        $toolbox->brief('new_leads', self::newLeads(...));
    }

    /** @return list<array<string, mixed>> the top five from Today's 10, with why */
    public static function callToday(User $user): array
    {
        $rows = array_slice(app(TodaysTen::class)->for($user, 5), 0, 5);

        return array_values(array_filter(array_map(fn (array $r): ?array => ($c = $r['contact'] ?? null) instanceof Contact
            ? ['id' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name), 'why' => array_values(array_map('strval', (array) ($r['reasons'] ?? [])))] : null, $rows)));
    }

    /** @return array{overdue: list<array<string, mixed>>, today: list<array<string, mixed>>} */
    public static function tasks(User $user): array
    {
        $tz = $user->timezone !== null && $user->timezone !== '' ? $user->timezone : 'America/New_York';
        $end = CarbonImmutable::now($tz)->endOfDay()->utc();
        $tasks = Task::query()->where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<=', $end)->orderBy('due_at')->limit(30)->get();
        $row = fn (Task $t): array => ['id' => $t->ulid, 'title' => $t->title, 'type' => $t->type, 'due' => $t->due_at->toIso8601String()];

        return ['overdue' => array_values($tasks->filter(fn (Task $t): bool => $t->due_at->isPast())->map($row)->all()),
            'today' => array_values($tasks->reject(fn (Task $t): bool => $t->due_at->isPast())->map($row)->all())];
    }

    /** @return list<array<string, mixed>> leads added in the last 24 hours that the person can see */
    public static function newLeads(User $user): array
    {
        return array_values(app(ContactVisibility::class)->query($user)->where('lifecycle', Lifecycle::Lead->value)
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())->latest('id')->limit(10)->get()
            ->map(fn (Contact $c): array => ['id' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name), 'source' => $c->source->value])->all());
    }
}
