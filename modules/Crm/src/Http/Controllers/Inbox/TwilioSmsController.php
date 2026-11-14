<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\Inbox;

/**
 * Twilio's incoming-message webhook for every number (specs/grow/05). The request must carry a valid Twilio
 * signature. The text is filed with the brokerage that owns the number, or that last texted this person.
 */
#[CrossTenantAccess('Incoming texts arrive at one webhook and are filed with the brokerage they belong to')]
final class TwilioSmsController
{
    public function __invoke(Request $request, MessagingSettings $settings, TenantContext $tenants): Response
    {
        // The signature covers the parameters exactly as Twilio sent them (before input trimming).
        parse_str($request->getContent(), $raw);
        if ($raw === []) {
            $raw = $request->post();
        }
        $token = $settings->credentials()['token'] ?? '';
        if (! TwilioSignature::valid($request->url(), $raw, (string) $request->header('X-Twilio-Signature', ''), $token)) {
            abort(403);
        }
        $from = is_string($raw['From'] ?? null) ? $raw['From'] : '';
        $to = is_string($raw['To'] ?? null) ? $raw['To'] : '';
        $twiml = static fn (?string $reply): Response => response('<?xml version="1.0" encoding="UTF-8"?><Response>'
            .($reply !== null ? '<Message>'.htmlspecialchars($reply, ENT_XML1 | ENT_QUOTES).'</Message>' : '').'</Response>', 200, ['Content-Type' => 'text/xml']);

        $tenantId = $settings->tenantForReply($from, $to);
        $tenant = $tenantId !== null ? Tenant::query()->whereKey($tenantId)->where('status', 'active')->first() : null;
        if ($tenant === null || preg_match('/^\+1\d{10}$/', $from) !== 1) {
            return $twiml(null);
        }

        $reply = $tenants->runAs($tenant, fn (): ?string => app(Inbox::class)->receiveSms($from, is_string($raw['Body'] ?? null) ? $raw['Body'] : '', is_string($raw['MessageSid'] ?? null) && $raw['MessageSid'] !== '' ? $raw['MessageSid'] : null));

        return $twiml($reply);
    }
}
