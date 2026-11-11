<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Task;
use Modules\Crm\Notifications\TaskReminder;

/**
 * Every 5 minutes: email reminders for tasks whose reminder time has come (each sent once).
 */
#[CrossTenantAccess('Send due task reminders for every tenant')]
final class SendTaskReminders extends Command
{
    protected $signature = 'crm:task-reminders';

    protected $description = 'Send due task reminders';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        $due = CrossTenant::query(Task::class, $this)
            ->whereNull('completed_at')->whereNull('reminded_at')
            ->whereNotNull('remind_at')->where('remind_at', '<=', CarbonImmutable::now())
            ->limit(1000)->get();

        foreach ($due as $task) {
            $tenant = Tenant::query()->find($task->tenant_id);
            if ($tenant === null) {
                continue;
            }

            $tenants->runAs($tenant, function () use ($task, &$sent): void {
                $user = User::query()->find($task->assigned_to);
                if ($user !== null && $user->isActive()) {
                    $tz = $user->timezone !== null && $user->timezone !== '' ? $user->timezone : 'America/New_York';
                    $user->notify(new TaskReminder($task->title, $task->contact?->name, $task->due_at->setTimezone($tz)->format('D j M, g:i a'),
                        url($task->contact !== null ? "/contacts/{$task->contact->ulid}" : '/tasks')));
                    $sent++;
                }
                $task->update(['reminded_at' => CarbonImmutable::now()]);
            });
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
