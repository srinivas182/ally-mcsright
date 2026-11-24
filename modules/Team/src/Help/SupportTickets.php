<?php

declare(strict_types=1);

namespace Modules\Team\Help;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Support requests to RightAlly HQ (CH1, specs/team/01). Kept in the control database so HQ can answer every
 * brokerage from one queue; the text is encrypted. HQ's answer is emailed to the person and shown on their Help
 * page. Asking for passwords, card numbers or bank details is never needed, and the form says so.
 */
final class SupportTickets
{
    public const TOPICS = ['how_to' => 'How do I…?', 'problem' => 'Something is not working', 'billing' => 'Billing and credits', 'idea' => 'An idea or request',
        'data' => 'My data or privacy', 'callback' => 'Please call me back', 'concierge' => 'Set it up for me']; // SX5 call me back, SX6 concierge setup

    public function __construct(private readonly TenantContext $tenants) {}

    public function open(User $by, string $topic, string $subject, string $body, ?string $page): string
    {
        $errors = [];
        if (! isset(self::TOPICS[$topic])) {
            $errors['topic'] = 'Choose what it is about.';
        }
        if (trim($subject) === '') {
            $errors['subject'] = 'Give it a short title.';
        }
        if (mb_strlen(trim($body)) < 10) {
            $errors['body'] = 'Tell us a little more, so we can help the first time.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('support_tickets')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $by->id,
            'user_name' => mb_substr((string) $by->name, 0, 160), 'user_email' => (string) $by->email, 'topic' => $topic, 'subject' => mb_substr(trim(strip_tags($subject)), 0, 160),
            'body' => Crypt::encryptString(mb_substr(trim($body), 0, 5000)), 'page' => $page !== null ? mb_substr($page, 0, 200) : null, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('team.support.opened', metadata: ['ticket' => $ulid, 'topic' => $topic]);

        return $ulid;
    }

    /** Features a brokerage can ask RightAlly to switch on (PG4), keyed as the pages send them. */
    public const SWITCH_ON = ['studio' => 'Ally Studio', 'social' => 'Social posting', 'ads' => 'Listing ads', 'postcards' => 'Postcards', 'voice' => 'AI voice-overs',
        'property_lookup' => 'Property lookups'];

    /**
     * PG4: "Ask RightAlly to switch it on". One open request per feature per brokerage; asking again does not add another.
     *
     * @return bool true when a new request was sent
     */
    public function askToSwitchOn(User $by, string $feature, ?string $page): bool
    {
        $name = self::SWITCH_ON[$feature] ?? throw ValidationException::withMessages(['feature' => 'Unknown feature.']);
        $subject = "Please switch on {$name}";
        $open = DB::connection(Connections::control())->table('support_tickets')->where('tenant_id', $this->tenants->id())->where('subject', $subject)
            ->where('status', 'open')->exists();
        if ($open) {
            return false;
        }
        $this->open($by, 'idea', $subject, "{$by->name} asked to switch on {$name} for this brokerage.", $page);

        return true;
    }

    /**
     * This person's requests in this brokerage, newest first.
     *
     * @return list<array{ulid: string, topic: string, subject: string, body: string, status: string, reply: string|null, created_at: string, replied_at: string|null, file: string|null}>
     */
    public function mine(User $user): array
    {
        return array_values(DB::connection(Connections::control())->table('support_tickets')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->orderByDesc('id')->limit(50)->get()->map(fn (\stdClass $t): array => self::row($t))->all());
    }

    public function close(string $ulid, User $user): void
    {
        DB::connection(Connections::control())->table('support_tickets')->where('ulid', $ulid)->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->update(['status' => 'closed', 'updated_at' => now()]);
    }

    /**
     * HQ's queue: open ones first, then recent answers.
     *
     * @return list<array<string, mixed>>
     */
    public static function queue(string $status = 'open'): array
    {
        $rows = DB::connection(Connections::control())->table('support_tickets')->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy($status === 'open' ? 'id' : 'updated_at', $status === 'open' ? 'asc' : 'desc')->limit(200)->get();
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->unique())->pluck('name', 'id');

        return array_values($rows->map(fn (\stdClass $t): array => self::row($t) + ['brokerage' => (string) ($names[$t->tenant_id] ?? '—'), 'name' => (string) $t->user_name,
            'email' => (string) $t->user_email, 'page' => $t->page])->all());
    }

    public static function reply(string $ulid, string $reply, User $by): void
    {
        $db = DB::connection(Connections::control())->table('support_tickets');
        $t = $db->where('ulid', $ulid)->first();
        if ($t === null) {
            abort(404);
        }
        if (mb_strlen(trim($reply)) < 2) {
            throw ValidationException::withMessages(['reply' => 'Write the answer.']);
        }
        DB::connection(Connections::control())->table('support_tickets')->where('id', $t->id)->update(['reply' => Crypt::encryptString(mb_substr(trim($reply), 0, 5000)),
            'status' => 'answered', 'replied_by' => $by->id, 'replied_at' => CarbonImmutable::now(), 'updated_at' => now()]);
        Notification::route('mail', (string) $t->user_email)->notify(new SupportReplyMail((string) $t->subject, trim($reply)));
        app(AuditLogger::class)->record('hq.support.replied', metadata: ['ticket' => $ulid, 'tenant' => (int) $t->tenant_id], severity: 'notice');
    }

    /** @return array{ulid: string, topic: string, subject: string, body: string, status: string, reply: string|null, created_at: string, replied_at: string|null, file: string|null} */
    private static function row(\stdClass $t): array
    {
        return ['ulid' => (string) $t->ulid, 'topic' => self::TOPICS[$t->topic] ?? (string) $t->topic, 'subject' => (string) $t->subject,
            'body' => Crypt::decryptString((string) $t->body), 'status' => (string) $t->status, 'reply' => $t->reply !== null ? Crypt::decryptString((string) $t->reply) : null,
            'created_at' => (string) $t->created_at, 'replied_at' => $t->replied_at !== null ? (string) $t->replied_at : null,
            'file' => isset($t->file_path) ? (string) ($t->file_name ?? 'file') : null];
    }
}
