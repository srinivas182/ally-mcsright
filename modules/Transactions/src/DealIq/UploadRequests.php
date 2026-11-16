<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;

/**
 * Asking the other side (specs/ai/02, §7.4): a secure, single-purpose upload link for the lender, title company or
 * co-op agent. No account is needed; the link works once, for 14 days, for one document. The file is filed on the
 * right checklist item and read by DocIQ. Only a hash of the link is stored.
 */
final class UploadRequests
{
    public const DAYS = 14;

    public function send(Deal $deal, ?Item $item, string $what, string $role, string $name, string $email, ?string $note, User $by): string
    {
        if ($deal->locked_at !== null) {
            throw ValidationException::withMessages(['request' => 'This deal is closed and locked.']);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($name) === '' || trim($what) === '') {
            throw ValidationException::withMessages(['request' => 'Say what you need, and who from (name and email).']);
        }
        if ($item !== null && $item->deal_id !== $deal->id) {
            throw ValidationException::withMessages(['item' => 'That checklist item belongs to another deal.']);
        }
        $token = Str::random(48);
        DB::connection(Connections::cell())->table('tx_upload_requests')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
            'item_id' => $item?->id, 'what' => mb_substr(trim($what), 0, 160), 'role' => mb_substr($role, 0, 20), 'name' => mb_substr(trim($name), 0, 160),
            'email' => mb_strtolower(trim($email)), 'token_hash' => hash('sha256', $token), 'requested_by' => $by->id,
            'expires_at' => CarbonImmutable::now()->addDays(self::DAYS), 'created_at' => now(), 'updated_at' => now()]);
        Notification::route('mail', mb_strtolower(trim($email)))->notify(new UploadRequestMail(trim($name), trim($what), (string) $deal->street, $by->name,
            $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, url('/deal-upload/'.$token)));
        app(AuditLogger::class)->record('transactions.upload_request.sent', $deal, metadata: ['what' => $what, 'role' => $role, 'to' => AuditLogger::mask($email)], label: $deal->street);

        return $token;
    }

    /**
     * The request behind a link, if it can still be used.
     */
    public function find(string $token): ?\stdClass
    {
        $row = DB::connection(Connections::cell())->table('tx_upload_requests')->where('token_hash', hash('sha256', $token))->first();

        return $row !== null && $row->used_at === null && CarbonImmutable::parse((string) $row->expires_at)->isFuture() ? $row : null;
    }

    public function receive(string $token, UploadedFile $file): Document
    {
        $row = $this->find($token) ?? throw ValidationException::withMessages(['file' => 'This link has been used or has expired. Ask the agent for a new one.']);
        $deal = Deal::query()->whereKey((int) $row->deal_id)->firstOrFail();
        $by = User::query()->whereKey((int) $row->requested_by)->firstOrFail();
        $item = $row->item_id !== null ? Item::query()->whereKey((int) $row->item_id)->first() : null;
        $doc = app(Documents::class)->upload($deal, $item, $file, 'link', $by);
        DB::connection(Connections::cell())->table('tx_upload_requests')->where('id', $row->id)->update(['used_at' => now(), 'document_id' => $doc->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.upload_request.received', $deal, metadata: ['what' => $row->what, 'from' => $row->name, 'filename' => $doc->filename], label: $deal->street);
        $by->notify(new UploadReceivedMail((string) $row->name, (string) $row->what, (string) $deal->street, '/deals/'.$deal->ulid));

        return $doc;
    }

    /**
     * @return list<array{what: string, name: string, role: string, status: string, sent_at: string}>
     */
    public function forDeal(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_upload_requests')->where('deal_id', $deal->id)->orderByDesc('id')->get()
            ->map(fn ($r): array => ['what' => (string) $r->what, 'name' => (string) $r->name, 'role' => (string) $r->role,
                'status' => $r->used_at !== null ? 'received' : (CarbonImmutable::parse((string) $r->expires_at)->isPast() ? 'expired' : 'waiting'),
                'sent_at' => CarbonImmutable::parse((string) $r->created_at)->toDateString()])->all());
    }
}
