<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\InboundMailboxes;
use Throwable;

/**
 * Deal-IQ intake by email (DQ4, D13, specs/ai/02). Each agent has their own address, dealiq-…@inbound…. Forwarding a
 * signed contract there starts a new deal exactly like uploading it: Deal-IQ reads the contract and the agent checks
 * and confirms the details. Only what is forwarded is read; RightAlly never reads a mailbox.
 *
 * Only mail from the agent (or one of their own assistants) is accepted, so a stranger who learns the address cannot
 * create deals; anything else is ignored and the agent is told. The first PDF (or photo) is the contract; at most 20
 * new deals a day per agent.
 */
final class EmailIntake
{
    public const PREFIX = 'dealiq';

    public const DAILY = 20;

    public function __construct(private readonly TenantContext $tenants, private readonly Intake $intake) {}

    /** The agent's address, made the first time it is asked for. */
    public function address(User $agent): string
    {
        $db = DB::connection(Connections::control());
        $token = $db->table('crm_inbound_addresses')->where('kind', self::PREFIX)->where('tenant_id', $this->tenants->id())->where('owner_user_id', $agent->id)->value('token');
        if (! is_string($token)) {
            $token = Str::random(40);
            $db->table('crm_inbound_addresses')->insert(['token' => $token, 'tenant_id' => $this->tenants->id(), 'kind' => self::PREFIX, 'owner_user_id' => $agent->id,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return InboundMailboxes::address(self::PREFIX, $token);
    }

    /**
     * @param  array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}  $email
     */
    public function receive(string $token, array $email): string
    {
        $ownerId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $token)->where('kind', self::PREFIX)->value('owner_user_id');
        $agent = $ownerId !== null ? User::query()->find((int) $ownerId) : null;
        if ($agent === null || $agent->status !== UserStatus::Active) {
            return 'ignored';
        }
        $from = self::address_of($email['from']);
        $allowed = User::query()->where('status', UserStatus::Active)->where(fn ($q) => $q->whereKey($agent->id)->orWhere('assisted_user_id', $agent->id))
            ->get()->first(fn (User $u): bool => mb_strtolower((string) $u->email) === $from);
        if ($allowed === null) {
            app(AuditLogger::class)->asAutomation('deal_iq_email', $agent->id, 'Deal-IQ email', fn () => app(AuditLogger::class)
                ->record('transactions.deal_iq.email_refused', metadata: ['from_domain' => substr((string) strrchr($from, '@'), 1)], severity: 'warning'));
            $agent->notify(new EmailIntakeRefused($from));

            return 'refused';
        }
        $file = collect($email['attachments'])->first(fn (array $a): bool => str_contains($a['type'], 'pdf') || str_ends_with(mb_strtolower($a['name']), '.pdf'))
            ?? collect($email['attachments'])->first(fn (array $a): bool => str_starts_with($a['type'], 'image/'));
        if ($file === null) {
            $agent->notify(new EmailIntakeDone(null, $email['subject'], 'There was no PDF or photo attached. Forward the email with the signed contract attached.'));

            return 'no_attachment';
        }
        if (! RateLimiter::attempt('deal-iq-email:'.$agent->id, self::DAILY, fn (): bool => true, 86400)) {
            $agent->notify(new EmailIntakeDone(null, $email['subject'], 'That is more than '.self::DAILY.' new deals by email today. Upload this one on the Deals page instead.'));

            return 'limited';
        }
        $path = tempnam(sys_get_temp_dir(), 'dealiq');
        try {
            file_put_contents($path, $file['content']);
            $upload = new UploadedFile($path, $file['name'], $file['type'] !== '' ? $file['type'] : null, null, true);
            $deal = $this->intake->fromFile($upload, $agent, $allowed);
        } catch (Throwable $e) {
            report($e);
            $agent->notify(new EmailIntakeDone(null, $email['subject'], 'The attachment could not be read. Upload it on the Deals page instead.'));

            return 'failed';
        } finally {
            @unlink($path);
        }
        app(AuditLogger::class)->record('transactions.deal_iq.email_intake', $deal, metadata: ['file' => $file['name']], label: $deal->street);
        $agent->notify(new EmailIntakeDone($deal->ulid, $email['subject'], null));

        return 'created';
    }

    private static function address_of(string $from): string
    {
        return mb_strtolower(preg_match('/<([^>]+)>/', $from, $m) === 1 ? trim($m[1]) : trim($from));
    }
}
