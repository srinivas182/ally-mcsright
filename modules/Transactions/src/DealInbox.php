<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\InboundMailboxes;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/**
 * Each deal's own email address (LM1, specs/transactions/30): agents, lenders and title forward contracts and
 * paperwork to deal-…@inbound…, and the PDFs and photos land in the deal's unsorted documents for the agent or broker
 * to file (never straight into a checklist item, never read by AI on arrival: that costs credits). Anything else in
 * the email is ignored; the same file twice is kept once. Works on closed deals too, since files are the deal's history.
 */
final class DealInbox
{
    public const PREFIX = 'deal';

    public function __construct(private readonly TenantContext $tenants, private readonly Documents $documents) {}

    /** The deal's address, made the first time it is asked for. */
    public function address(Deal $deal): string
    {
        $token = $deal->getAttribute('inbox_token');
        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $deal->forceFill(['inbox_token' => $token])->save();
            DB::connection(Connections::control())->table('crm_inbound_addresses')->insert(['token' => $token, 'tenant_id' => $this->tenants->id(), 'kind' => self::PREFIX,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return InboundMailboxes::address(self::PREFIX, $token);
    }

    /**
     * @param  array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}  $email
     */
    public function receive(string $token, array $email): string
    {
        $deal = Deal::query()->where('inbox_token', $token)->first();
        if ($deal === null) {
            return 'ignored';
        }
        $added = [];
        foreach ($email['attachments'] as $a) {
            $doc = $this->documents->imported($deal, $a['name'], $a['content'], 'email');
            if ($doc instanceof Document) {
                $added[] = $doc->filename;
            }
        }
        app(AuditLogger::class)->record('transactions.deal_email_in', $deal, metadata: ['from' => mb_substr($email['from'], 0, 120), 'subject' => mb_substr($email['subject'], 0, 120),
            'files' => $added], severity: 'info');

        return $added === [] ? 'no_files' : 'filed';
    }
}
