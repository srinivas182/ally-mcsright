<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\SendGuard;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;

/**
 * Calls through the Movewise relay (specs/movewise/08, finishing the bridge of 05). Twilio's voice webhook for each
 * relay number. The person calls their Movewise number and reaches their agent; the agent calls the same number
 * from their own phone and reaches the person. Both sides see only the relay number. Calls to the person keep the
 * 8am–9pm quiet hours; nothing connects while the person has paused, stopped or changed agent.
 */
#[CrossTenantAccess('Relay calls arrive at one webhook and connect within one introduction')]
final class VoiceRelayController
{
    public function __invoke(Request $request, MessagingSettings $settings, TenantContext $tenants): Response
    {
        parse_str($request->getContent(), $raw);
        if ($raw === []) {
            $raw = $request->post();
        }
        if (! TwilioSignature::valid($request->url(), $raw, (string) $request->header('X-Twilio-Signature', ''), (string) ($settings->credentials()['token'] ?? ''))) {
            abort(403);
        }
        $from = is_string($raw['From'] ?? null) ? $raw['From'] : '';
        $relay = is_string($raw['To'] ?? null) ? $raw['To'] : '';
        $live = fn () => Connection::query()->where('relay_number', $relay)->where('status', 'accepted')->whereNull('handed_over_at');

        // The person calling their Movewise number.
        foreach ($live()->get() as $c) {
            $p = Person::query()->find($c->person_id);
            if ($p === null || $p->phone !== $from) {
                continue;
            }
            if ($c->paused_until?->isFuture()) {
                return self::say('You paused contact through Movewise. To talk with your agent again, resume in My Movewise or text RESUME to this number.');
            }
            $agentPhone = $this->agentPhone($c, $tenants);

            return $agentPhone !== null ? self::dial($relay, $agentPhone) : self::say('Your agent can\'t take calls on this number yet. Please text them here instead.');
        }
        // The agent calling their client.
        foreach ($live()->get() as $c) {
            if ($this->agentPhone($c, $tenants) !== $from) {
                continue;
            }
            $p = Person::query()->find($c->person_id);
            if ($p === null || $p->phone === null || $c->paused_until?->isFuture()) {
                return self::say('This client has paused contact through Movewise.');
            }
            $quiet = SendGuard::quietHours($p->phone);

            return $quiet !== null ? self::say('It is outside 8am to 9pm where your client is. Please call between 8am and 9pm their time.') : self::dial($relay, $p->phone);
        }

        return self::say('This Movewise number isn\'t connected to an introduction. Visit movewise homes dot com.');
    }

    private function agentPhone(Connection $c, TenantContext $tenants): ?string
    {
        $agent = Agent::query()->find($c->agent_id);
        $tenant = Tenant::query()->find($c->tenant_id);
        if ($agent === null || $tenant === null) {
            return null;
        }
        $phone = $tenants->runAs($tenant, fn (): ?string => User::query()->find($agent->user_id)?->phone);

        return is_string($phone) && preg_match('/^\+1\d{10}$/', $phone) === 1 ? $phone : null;
    }

    private static function dial(string $callerId, string $to): Response
    {
        return self::twiml('<Dial callerId="'.htmlspecialchars($callerId, ENT_XML1 | ENT_QUOTES).'" timeout="25"><Number>'.htmlspecialchars($to, ENT_XML1 | ENT_QUOTES).'</Number></Dial>');
    }

    private static function say(string $text): Response
    {
        return self::twiml('<Say>'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES).'</Say>');
    }

    private static function twiml(string $body): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response>'.$body.'</Response>', 200, ['Content-Type' => 'text/xml']);
    }
}
