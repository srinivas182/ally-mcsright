<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Notices\NoticeDigestMail;
use Modules\Core\Notices\Notices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Hourly: alerts held for quiet hours go out once quiet hours end; daily-digest people get one email at 7am their
 * time (specs/core/10, N1).
 */
#[CrossTenantAccess('Send held alerts and daily digests for every brokerage')]
final class SendNoticeDigests extends Command
{
    protected $signature = 'core:notice-digests';

    protected $description = 'Send alerts held for quiet hours and the daily digests';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            try {
                $sent += $tenants->runAs($tenant, fn (): int => $this->forTenant());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} digest(s) sent.");

        return self::SUCCESS;
    }

    private function forTenant(): int
    {
        $notices = app(Notices::class);
        $sent = 0;
        foreach ($notices->pendingEmail() as $userId => $items) {
            $user = User::query()->find($userId);
            if (! $user instanceof User || ! $user->isActive()) {
                $notices->emailed(array_map(fn (\stdClass $n): int => (int) $n->id, $items));

                continue;
            }
            $settings = $notices->settings($user);
            $local = CarbonImmutable::now()->setTimezone($user->timezone ?: 'America/Chicago');
            if ($settings['digest']) {
                $last = DB::connection(Connections::cell())->table('notice_settings')->where('user_id', $user->id)->value('digest_sent_at');
                $sentToday = $last !== null && CarbonImmutable::parse((string) $last)->setTimezone($local->getTimezone())->isSameDay($local);
                if ($local->hour < 7 || $sentToday || $notices->quiet($user)) {
                    continue;
                }
            } elseif ($notices->quiet($user)) {
                continue;
            }
            $user->notify(new NoticeDigestMail(array_map(fn (\stdClass $n): array => ['title' => (string) $n->title, 'url' => $n->url !== null ? (string) $n->url : null], $items)));
            $notices->emailed(array_map(fn (\stdClass $n): int => (int) $n->id, $items));
            DB::connection(Connections::cell())->table('notice_settings')->where('user_id', $user->id)->update(['digest_sent_at' => CarbonImmutable::now()]);
            $sent++;
        }

        return $sent;
    }
}
