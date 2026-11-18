<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\InboundMailboxes;
use Modules\Crm\Inbox\InboundPayload;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Leads\PortalEmail;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;

/**
 * One inbound-email webhook for every brokerage (Postmark inbound JSON, basic-auth secret; DQ4: also SendGrid
 * Inbound Parse and Mailgun Routes, whichever HQ chose in Outside services). The forwarding
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

        return response()->json(['status' => $this->handle(InboundPayload::postmark($request), $tenants)]);
    }

    /** DQ4: SendGrid Inbound Parse, with the shared secret in the address (SendGrid does not sign). */
    public function sendgrid(Request $request, TenantContext $tenants, string $secret): JsonResponse
    {
        $expected = (string) config('crm.inbound_email_secret');
        if ($expected === '' || ! hash_equals($expected, $secret) || (app(OutsideServices::class)->active('inbound_email')['provider'] ?? null) !== 'sendgrid') {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        return response()->json(['status' => $this->handle(InboundPayload::sendgrid($request), $tenants)]);
    }

    /** DQ4: Mailgun Routes, checked with Mailgun's webhook signing key. */
    public function mailgun(Request $request, TenantContext $tenants): JsonResponse
    {
        $service = app(OutsideServices::class)->active('inbound_email');
        if (($service['provider'] ?? null) !== 'mailgun' || ! InboundPayload::mailgunSigned($request, (string) ($service['keys']['signing_key'] ?? ''))) {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        return response()->json(['status' => $this->handle(InboundPayload::mailgun($request), $tenants)]);
    }

    /**
     * @param  array{recipients: string, from: string, reply_to: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}  $email
     */
    private function handle(array $email, TenantContext $tenants): string
    {
        $recipients = $email['recipients'];
        if (preg_match('/reply-([A-Za-z0-9]{40})@/', $recipients, $r) === 1) {
            return $this->reply($email, $r[1], $tenants);
        }
        foreach (app(InboundMailboxes::class)->all() as $prefix => $handler) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($prefix, '/').'-([A-Za-z0-9]{40})@/', $recipients, $x) === 1) {
                return $this->mailbox($email, $prefix, $x[1], $handler, $tenants);
            }
        }
        if (preg_match('/leads-([A-Za-z0-9]{40})@/', $recipients, $m) !== 1) {
            return 'ignored';
        }
        $tenantId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $m[1])->value('tenant_id');
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null) {
            return 'ignored';
        }

        return $tenants->runAs($tenant, function () use ($email, $m): string {
            $source = LeadSource::query()->where('token', $m[1])->where('kind', 'email')->where('active', true)->first();
            if ($source === null) {
                return 'ignored';
            }
            $read = app(PortalEmail::class)->read(['from' => $email['from'], 'reply_to' => $email['reply_to'], 'subject' => $email['subject'], 'text' => $email['text']]);
            try {
                $lead = app(LeadIntake::class)->receive($source, [...$read, 'external_id' => $email['message_id']]);
            } catch (ValidationException) {
                Lead::query()->create(['source_id' => $source->id, 'source' => $read['source'] !== 'other' ? $read['source'] : $source->source->value, 'channel' => 'portal',
                    'status' => 'needs_review', 'new_contact' => false, 'property' => $read['property'],
                    'raw' => mb_substr("From: {$email['from']}\nSubject: {$email['subject']}\n\n".PortalEmail::plain($email['text']), 0, 20000)]);

                return 'needs_review';
            }

            return $lead->status;
        });
    }

    /**
     * Another module's address (LM1): find the brokerage from the token, then hand over the email and its PDF and
     * image attachments to the module's handler, run as that brokerage.
     *
     * @param  array{recipients: string, from: string, reply_to: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}  $email
     * @param  \Closure(string, array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}): string  $handler
     */
    private function mailbox(array $email, string $prefix, string $token, \Closure $handler, TenantContext $tenants): string
    {
        $tenantId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $token)->where('kind', $prefix)->value('tenant_id');
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null) {
            return 'ignored';
        }
        $mail = ['from' => $email['from'], 'subject' => $email['subject'], 'text' => $email['text'], 'message_id' => $email['message_id'], 'attachments' => $email['attachments']];

        return $tenants->runAs($tenant, fn (): string => $handler($token, $mail));
    }

    /**
     * A reply to an agent's email (sent with the conversation's reply address): into the inbox.
     *
     * @param  array{recipients: string, from: string, reply_to: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}  $email
     */
    private function reply(array $email, string $token, TenantContext $tenants): string
    {
        $tenantId = DB::connection(Connections::control())->table('crm_inbound_addresses')->where('token', $token)->where('kind', 'reply')->value('tenant_id');
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null) {
            return 'ignored';
        }

        return $tenants->runAs($tenant, function () use ($email, $token): string {
            $conversation = Conversation::query()->where('reply_token', $token)->with('contact')->first();
            if ($conversation === null) {
                return 'ignored';
            }
            app(Inbox::class)->receiveEmail($conversation, $email['from'], $email['subject'], $email['text'], $email['message_id']);

            return 'received';
        });
    }
}
