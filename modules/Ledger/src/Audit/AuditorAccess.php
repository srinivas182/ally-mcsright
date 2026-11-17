<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Read-only access for an outside auditor or CPA that ends by itself (FH2, specs/finance/23). The auditor
 * is invited as staff with the "Outside auditor (read-only)" role, which expires on the chosen date. They
 * can read the books and download statements and audit packs; they can't change anything.
 */
final class AuditorAccess
{
    public const ROLE = 'auditor';

    public const MIN_DAYS = 7;

    public const MAX_DAYS = 90;

    public function __construct(private readonly TenantContext $tenants, private readonly Roles $roles, private readonly AuditLogger $audit) {}

    public function grant(string $email, string $firstName, string $lastName, string $firm, int $days, User $by): string
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw ValidationException::withMessages(['days' => 'Choose between '.self::MIN_DAYS.' and '.self::MAX_DAYS.' days.']);
        }
        $email = mb_strtolower(trim($email));
        $invitation = app(ManageInvitations::class)->invite($email, $firstName, $lastName, UserKind::Staff, $by);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('ledger_auditor_access')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'email' => $email,
            'name' => mb_substr(trim($firstName.' '.$lastName), 0, 160), 'firm' => mb_substr(trim($firm), 0, 160), 'invitation_id' => $invitation->id,
            'expires_at' => CarbonImmutable::now()->addDays($days)->endOfDay(), 'granted_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('finance.auditor.invited', metadata: ['access' => $ulid, 'firm' => $firm, 'days' => $days], severity: 'warning');

        return $ulid;
    }

    /** When the auditor accepts the invitation, give them the read-only role until the end date. */
    public function onActivated(UserActivated $event): void
    {
        $user = User::query()->find($event->userId);
        if (! $user instanceof User) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $row = $db->table('ledger_auditor_access')->where('tenant_id', $user->tenant_id)->where('email', $user->email)->whereNull('user_id')->whereNull('revoked_at')->first();
        if ($row === null) {
            return;
        }
        $expires = CarbonImmutable::parse((string) $row->expires_at);
        $by = User::query()->find((int) $row->granted_by);
        if ($expires->isPast() || ! $by instanceof User) {
            return;
        }
        $this->roles->assign($user, $this->role($by), $by, $expires, 'Outside auditor'.((string) $row->firm !== '' ? ' ('.$row->firm.')' : ''));
        $db->table('ledger_auditor_access')->where('id', $row->id)->update(['user_id' => $user->id, 'updated_at' => now()]);
    }

    public function revoke(string $ulid, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('ledger_auditor_access')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->whereNull('revoked_at')->first();
        if ($row === null) {
            throw ValidationException::withMessages(['access' => 'This access has already ended.']);
        }
        $user = $row->user_id !== null ? User::query()->find((int) $row->user_id) : null;
        if ($user instanceof User) {
            $this->roles->revoke($user, $this->role($by), 'auditor access ended');
        } else {
            $invitation = Invitation::query()->find((int) $row->invitation_id);
            if ($invitation instanceof Invitation && $invitation->accepted_at === null && $invitation->revoked_at === null) {
                app(ManageInvitations::class)->revoke($invitation, $by);
            }
        }
        $db->table('ledger_auditor_access')->where('id', $row->id)->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'updated_at' => now()]);
        $this->audit->record('finance.auditor.revoked', metadata: ['access' => $ulid], severity: 'warning');
    }

    /**
     * @return list<array{ulid: string, name: string, email: string, firm: string, status: string, expires_at: string}>
     */
    public function list(): array
    {
        return array_values(DB::connection(Connections::cell())->table('ledger_auditor_access')->where('tenant_id', $this->tenants->id())->orderByDesc('id')->limit(50)->get()
            ->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'name' => (string) $r->name, 'email' => (string) $r->email, 'firm' => (string) $r->firm,
                'status' => $r->revoked_at !== null ? 'ended' : (CarbonImmutable::parse((string) $r->expires_at)->isPast() ? 'expired' : ($r->user_id !== null ? 'active' : 'invited')),
                'expires_at' => CarbonImmutable::parse((string) $r->expires_at)->toDateString()])->all());
    }

    /** The brokerage's auditor role, added from the ready-made template if it started before the role existed. */
    private function role(User $by): Role
    {
        $this->roles->all();

        return Role::query()->where('template_key', self::ROLE)->first() ?? $this->roles->addTemplate(self::ROLE, $by);
    }
}
