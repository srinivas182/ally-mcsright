<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Permissions;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;
use Throwable;

/**
 * Privacy requests from the public (TR1, specs/core/18): see what the brokerage holds about them, delete it, correct
 * it, or stop it being sold or shared. The person confirms by email first (so nobody can ask about someone else);
 * the brokerage then has 45 days to answer (the longest US state privacy laws allow without an extension).
 *
 * - See: they get a copy of every record found, by a link valid for 7 days.
 * - Delete: every record found is deleted, except records the brokerage must keep by law (a transaction file); the
 *   answer says which were kept and why.
 * - Stop selling or sharing: RightAlly never sells personal information; marketing emails and texts are stopped.
 * - Correct: the brokerage makes the change and records what it did.
 */
final class PrivacyRequests
{
    public const KINDS = ['know' => 'See my information', 'delete' => 'Delete my information', 'correct' => 'Correct my information', 'opt_out' => 'Stop selling or sharing my information'];

    public const DAYS = 45;

    public const LINK_DAYS = 7;

    public function __construct(private readonly TenantContext $tenants, private readonly PrivacyRegistry $registry) {}

    /**
     * A request from the brokerage's website. Returns the confirmation token to email (never stored in plain text).
     *
     * @param  array{kind: string, name: string, email: string, phone?: string|null, details?: string|null}  $in
     */
    public function ask(array $in): string
    {
        $errors = [];
        if (! isset(self::KINDS[$in['kind']])) {
            $errors['kind'] = 'Choose what you would like us to do.';
        }
        if (trim($in['name']) === '' || mb_strlen($in['name']) > 160) {
            $errors['name'] = 'Please give your name.';
        }
        if (filter_var(trim($in['email']), FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Please give the email address we can reach you at.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $token = Str::random(48);
        DB::connection(Connections::cell())->table('privacy_requests')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'kind' => $in['kind'],
            'name' => mb_substr(trim($in['name']), 0, 160), 'email' => mb_strtolower(trim($in['email'])), 'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30) ?: null,
            'details' => mb_substr(trim((string) ($in['details'] ?? '')), 0, 4000) ?: null, 'status' => 'unconfirmed', 'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now()]);

        return $token;
    }

    /** The person clicked the link in the email: the request opens and the brokerage is told. */
    public function confirm(string $token): ?stdClass
    {
        $db = DB::connection(Connections::cell())->table('privacy_requests');
        $req = $db->clone()->where('tenant_id', $this->tenants->id())->where('token_hash', hash('sha256', $token))->where('status', 'unconfirmed')
            ->where('created_at', '>', now()->subDays(7))->first();
        if ($req === null) {
            return null;
        }
        $db->clone()->where('id', $req->id)->update(['status' => 'open', 'token_hash' => null, 'confirmed_at' => now(), 'due_on' => now()->addDays(self::DAYS)->toDateString(),
            'updated_at' => now()]);
        app(AuditLogger::class)->record('core.privacy.request_confirmed', metadata: ['request' => $req->ulid, 'kind' => $req->kind], severity: 'warning');
        Notification::send($this->handlers(), new PrivacyRequestMail((string) $req->name, self::KINDS[(string) $req->kind], now()->addDays(self::DAYS)->toDateString()));

        return $req;
    }

    /**
     * The records found for a request, by source, with any reason a record must be kept.
     *
     * @return list<array{source: string, label: string, id: int, record: string, held: string|null}>
     */
    public function matches(stdClass $req): array
    {
        $out = [];
        foreach ($this->registry->sources() as $key => $source) {
            try {
                $found = ($source['find'])((string) $req->email, $req->phone !== null ? (string) $req->phone : null);
            } catch (Throwable) {
                continue;
            }
            foreach ($found as $f) {
                $out[] = ['source' => $key, 'label' => $source['label'], 'id' => $f['id'], 'record' => $f['label'], 'held' => $this->registry->heldBecause($key, $f['id'])];
            }
        }

        return $out;
    }

    /**
     * A copy of everything found, for the person (served by a link valid for 7 days).
     *
     * @return array<string, mixed>
     */
    public function copy(stdClass $req): array
    {
        $sources = $this->registry->sources();
        $records = [];
        foreach ($this->matches($req) as $m) {
            $records[$m['label']][] = ($sources[$m['source']]['export'])($m['id']);
        }

        return ['about' => 'Information '.$this->tenants->current()->name.' holds about '.$req->name, 'made_on' => now()->toDateString(), 'records' => $records];
    }

    /** Carries out a request. Returns the answer recorded and shown to the brokerage. */
    public function complete(string $ulid, User $by, ?string $note = null): string
    {
        $req = $this->open($ulid);
        $sources = $this->registry->sources();
        $matches = $this->matches($req);
        $outcome = match ((string) $req->kind) {
            'know' => 'We sent a copy of '.count($matches).' record(s), by a link valid for '.self::LINK_DAYS.' days.',
            'delete' => $this->erase($matches, $sources),
            'opt_out' => $this->optOut($matches, $sources),
            default => trim((string) $note) !== '' ? 'Corrected: '.trim((string) $note) : throw ValidationException::withMessages(['note' => 'Say what you corrected.']),
        };
        if ($req->kind === 'know') {
            Notification::route('mail', (string) $req->email)->route('name', (string) ($req->name ?? ''))->notify(new PrivacyCopyMail((string) $req->name, (string) $this->tenants->current()->name, $this->downloadLink($req)));
        } else {
            Notification::route('mail', (string) $req->email)->route('name', (string) ($req->name ?? ''))->notify(new PrivacyAnswerMail((string) $req->name, (string) $this->tenants->current()->name, $outcome));
        }
        $this->close((int) $req->id, 'done', $outcome, $by);

        return $outcome;
    }

    public function decline(string $ulid, User $by, string $reason): void
    {
        $req = $this->open($ulid);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['note' => 'Explain why, in a sentence the person will understand.']);
        }
        Notification::route('mail', (string) $req->email)->route('name', (string) ($req->name ?? ''))->notify(new PrivacyAnswerMail((string) $req->name, (string) $this->tenants->current()->name, 'We could not do this: '.$reason));
        $this->close((int) $req->id, 'declined', 'Declined: '.$reason, $by);
    }

    /** @return list<stdClass> */
    public function list(): array
    {
        return array_values(DB::connection(Connections::cell())->table('privacy_requests')->where('tenant_id', $this->tenants->id())->where('status', '!=', 'unconfirmed')
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderBy('due_on')->orderByDesc('id')->limit(100)->get()->all());
    }

    public function find(string $ulid): ?stdClass
    {
        /** @var stdClass|null */
        return DB::connection(Connections::cell())->table('privacy_requests')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
    }

    /** Owners and people with "Handle privacy requests". */
    public function canHandle(User $user): bool
    {
        return $user->kind === UserKind::Owner || app(Permissions::class)->allows($user, 'core.privacy.manage');
    }

    /** Open requests due within a week (or late): a reminder for the people who handle them. */
    public function remind(): int
    {
        $due = DB::connection(Connections::cell())->table('privacy_requests')->where('tenant_id', $this->tenants->id())->where('status', 'open')
            ->where('due_on', '<=', now()->addDays(7)->toDateString())->count();
        if ($due > 0) {
            Notification::send($this->handlers(), new PrivacyDueMail($due));
        }

        return $due;
    }

    private function downloadLink(stdClass $req): string
    {
        return URL::temporarySignedRoute('privacy.copy', now()->addDays(self::LINK_DAYS), ['request_ulid' => $req->ulid]);
    }

    /**
     * @param  list<array{source: string, label: string, id: int, record: string, held: string|null}>  $matches
     * @param  array<string, array<string, mixed>>  $sources
     */
    private function erase(array $matches, array $sources): string
    {
        if ($matches === []) {
            return 'We found no records about this person.';
        }
        $done = 0;
        $kept = [];
        foreach ($matches as $m) {
            if ($m['held'] !== null) {
                $kept[] = $m['record'].' ('.$m['held'].')';

                continue;
            }
            ($sources[$m['source']]['erase'])($m['id']);
            $done++;
        }
        app(AuditLogger::class)->record('core.privacy.erased', metadata: ['deleted' => $done, 'kept' => count($kept)], severity: 'warning');

        return 'Deleted '.$done.' record(s).'.($kept !== [] ? ' Kept because the law requires it: '.implode('; ', $kept).'.' : '');
    }

    /**
     * @param  list<array{source: string, label: string, id: int, record: string, held: string|null}>  $matches
     * @param  array<string, array<string, mixed>>  $sources
     */
    private function optOut(array $matches, array $sources): string
    {
        foreach ($matches as $m) {
            ($sources[$m['source']]['opt_out'])($m['id']);
        }

        return 'We never sell or share personal information for money. Marketing emails and texts to this person are stopped ('.count($matches).' record(s)).';
    }

    private function open(string $ulid): stdClass
    {
        $req = $this->find($ulid);
        if ($req === null || $req->status !== 'open') {
            throw ValidationException::withMessages(['request' => 'This request is already answered.']);
        }

        return $req;
    }

    private function close(int $id, string $status, string $outcome, User $by): void
    {
        DB::connection(Connections::cell())->table('privacy_requests')->where('id', $id)->update(['status' => $status, 'outcome' => mb_substr($outcome, 0, 1000),
            'handled_by' => $by->id, 'handled_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('core.privacy.request_'.$status, metadata: ['request' => $id], severity: 'warning');
    }

    /** @return Collection<int, User> */
    private function handlers(): Collection
    {
        return User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Owner, UserKind::Staff])->get()->filter(fn (User $u): bool => $this->canHandle($u))->values();
    }
}
