<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Leads\PortalEmail;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;

/**
 * One inbound-email webhook for every brokerage (Postmark inbound JSON, basic-auth secret). The forwarding
 * address's token says which brokerage and source; the email is read and becomes a lead, or is kept for review.
 * Always answers 200 for unknown addresses so the provider does not retry forever.
 */
#[CrossTenantAccess('Inbound lead emails arrive at one address and are handed to the brokerage they were forwarded for')]
final class InboundEmailController
{
    public function __invoke(Request $request, TenantContext $tenants): JsonResponse
    {
        $secret = (string) config('crm.inbound_email_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->getPassword())) {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $recipients = implode(' ', array_filter([(string) $request->input('OriginalRecipient', ''), (string) $request->input('To', ''),
            ...array_map(static fn ($r): string => is_array($r) ? (string) ($r['Email'] ?? '') : '', (array) $request->input('ToFull', []))]));
        if (preg_match('/reply-([A-Za-z0-9]{40})@/', $recipients, $r) === 1) {
            return response()->json(['status' => $this->reply($request, $r[1], $tenants)]);
        }
        if (preg_match('/leads-([A-Za-z0-9]{40})@/', $recipients, $m) !== 1) {
            return response()->json(['status' => 'ignored']);
        }
        $tenantId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $m[1])->value('tenant_id');
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null) {
            return response()->json(['status' => 'ignored']);
        }

        $status = $tenants->runAs($tenant, function () use ($request, $m): string {
            $source = LeadSource::query()->where('token', $m[1])->where('kind', 'email')->where('active', true)->first();
            if ($source === null) {
                return 'ignored';
            }
            $email = ['from' => (string) $request->input('From', ''), 'reply_to' => (string) $request->input('ReplyTo', ''),
                'subject' => mb_substr((string) $request->input('Subject', ''), 0, 300),
                'text' => (string) ($request->input('TextBody') ?: $request->input('HtmlBody', ''))];
            $read = app(PortalEmail::class)->read($email);
            try {
                $lead = app(LeadIntake::class)->receive($source, [...$read, 'external_id' => mb_substr((string) $request->input('MessageID', ''), 0, 100) ?: null]);
            } catch (ValidationException) {
                Lead::query()->create(['source_id' => $source->id, 'source' => $read['source'] !== 'other' ? $read['source'] : $source->source->value, 'channel' => 'portal',
                    'status' => 'needs_review', 'new_contact' => false, 'property' => $read['property'],
                    'raw' => mb_substr("From: {$email['from']}\nSubject: {$email['subject']}\n\n".PortalEmail::plain($email['text']), 0, 20000)]);

                return 'needs_review';
            }

            return $lead->status;
        });

        return response()->json(['status' => $status]);
    }

    /** A reply to an agent's email (sent with the conversation's reply address): into the inbox. */
    private function reply(Request $request, string $token, TenantContext $tenants): string
    {
        $tenantId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $token)->where('kind', 'reply')->value('tenant_id');
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null) {
            return 'ignored';
        }

        return $tenants->runAs($tenant, function () use ($request, $token): string {
            $conversation = Conversation::query()->where('reply_token', $token)->with('contact')->first();
            if ($conversation === null) {
                return 'ignored';
            }
            app(Inbox::class)->receiveEmail($conversation, (string) $request->input('From', ''), (string) $request->input('Subject', ''),
                (string) ($request->input('TextBody') ?: $request->input('HtmlBody', '')), (string) $request->input('MessageID', '') ?: null);

            return 'received';
        });
    }
}
