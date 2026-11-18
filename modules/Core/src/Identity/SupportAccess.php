<?php

declare(strict_types=1);

namespace Modules\Core\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Notifications\SupportAccessRequested;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Support access (FL1, specs/core/02 and 16): the brokerage decides whether RightAlly support may view its account
 * without asking ("open", the default) or must ask first ("ask"). When it must ask, support sends a request with a
 * reason; an owner allows it for 24 hours (or declines). Every support visit is listed for the brokerage: who, when,
 * whose account and why. The visit itself keeps every safeguard of "Log in as" (60 minutes, banner, sensitive actions
 * blocked, the person emailed).
 */
final class SupportAccess
{
    public const HOURS = 24;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{policy: string, open_until: string|null} */
    public function policy(int $tenantId): array
    {
        $row = DB::connection(Connections::control())->table('support_access')->where('tenant_id', $tenantId)->first();

        return ['policy' => (string) ($row->policy ?? 'open'), 'open_until' => $row->open_until ?? null];
    }

    public function mayEnter(int $tenantId): bool
    {
        $p = $this->policy($tenantId);

        return $p['policy'] === 'open' || ($p['open_until'] !== null && CarbonImmutable::parse($p['open_until'])->isFuture());
    }

    public function setPolicy(string $policy, User $by): void
    {
        if (! in_array($policy, ['open', 'ask'], true)) {
            throw ValidationException::withMessages(['policy' => 'Choose "Support may help without asking" or "Support must ask first".']);
        }
        DB::connection(Connections::control())->table('support_access')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['policy' => $policy, 'open_until' => null, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('core.support_access.policy', metadata: ['policy' => $policy], severity: 'warning');
    }

    /** HQ support asks to view a brokerage that asks first. The owners are emailed. */
    public function request(User $staff, Tenant $tenant, string $reason): void
    {
        $control = DB::connection(Connections::control())->table('support_access_requests');
        if ($control->clone()->where('tenant_id', $tenant->id)->where('status', 'waiting')->where('created_at', '>', now()->subDay())->exists()) {
            return; // one waiting request at a time
        }
        $control->clone()->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'by_user_id' => $staff->id, 'by_name' => mb_substr($staff->name, 0, 160),
            'reason' => mb_substr(trim($reason), 0, 300), 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
        $domain = $tenant->primaryDomain();
        $link = 'https://'.($domain !== null ? $domain->host : $tenant->slug.'.'.config('core.platform_domain')).'/settings/support-access';
        $this->tenants->runAs($tenant, function () use ($staff, $reason, $link): void {
            $owners = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
            Notification::send($owners, new SupportAccessRequested($staff->name, trim($reason), $link));
        });
        app(AuditLogger::class)->record('core.support_access.requested', metadata: ['by' => $staff->id, 'reason' => trim($reason)], severity: 'warning', tenantId: $tenant->id);
    }

    /** An owner allows (for 24 hours) or declines a waiting request. */
    public function decide(string $ulid, bool $allow, User $owner): void
    {
        $control = DB::connection(Connections::control());
        $req = $control->table('support_access_requests')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->where('status', 'waiting')->first()
            ?? throw ValidationException::withMessages(['request' => 'That request is already answered.']);
        $control->table('support_access_requests')->where('id', $req->id)->update(['status' => $allow ? 'allowed' : 'declined', 'decided_by' => $owner->id, 'decided_at' => now(), 'updated_at' => now()]);
        if ($allow) {
            $control->table('support_access')->updateOrInsert(['tenant_id' => $this->tenants->id()],
                ['policy' => $this->policy($this->tenants->id())['policy'], 'open_until' => now()->addHours(self::HOURS), 'updated_by' => $owner->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        app(AuditLogger::class)->record('core.support_access.'.($allow ? 'allowed' : 'declined'), metadata: ['request' => $ulid], severity: 'warning');
    }

    /** The owner ends an allowed window early. */
    public function close(User $owner): void
    {
        DB::connection(Connections::control())->table('support_access')->where('tenant_id', $this->tenants->id())->update(['open_until' => null, 'updated_by' => $owner->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('core.support_access.closed', severity: 'warning');
    }

    public function recordVisit(Tenant $tenant, User $staff, User $viewed, string $reason): void
    {
        DB::connection(Connections::control())->table('support_visits')->insert(['tenant_id' => $tenant->id, 'by_user_id' => $staff->id, 'by_name' => mb_substr($staff->name, 0, 160),
            'viewed_name' => mb_substr($viewed->name, 0, 160), 'reason' => mb_substr(trim($reason), 0, 300), 'created_at' => now()]);
    }

    /**
     * @return array{policy: string, open_until: string|null, waiting: list<array{ulid: string, by: string, reason: string, at: string}>, visits: list<array{by: string, viewed: string, reason: string, at: string}>}
     */
    public function summary(): array
    {
        $control = DB::connection(Connections::control());
        $id = $this->tenants->id();
        $p = $this->policy($id);

        return $p + [
            'waiting' => $control->table('support_access_requests')->where('tenant_id', $id)->where('status', 'waiting')->orderByDesc('id')->limit(10)->get()
                ->map(fn (object $r): array => ['ulid' => (string) $r->ulid, 'by' => (string) $r->by_name, 'reason' => (string) $r->reason, 'at' => (string) $r->created_at])->values()->all(),
            'visits' => $control->table('support_visits')->where('tenant_id', $id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (object $v): array => ['by' => (string) $v->by_name, 'viewed' => (string) $v->viewed_name, 'reason' => (string) $v->reason, 'at' => (string) $v->created_at])->values()->all(),
        ];
    }
}
