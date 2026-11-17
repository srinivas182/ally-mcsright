<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\Webhooks\SafeUrl;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Outbox;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\LeadSource;

/**
 * Zapier and Make, ready to use (CH1, specs/connect/22). They sign in with a Lead API key the broker made (Grow ›
 * Leads › Sources), then:
 *  - GET  api/zapier/me               checks the key ("Connected to Oak Realty, key Zillow via Zapier");
 *  - POST api/zapier/hooks            subscribes an instant trigger (REST hook) for one event;
 *  - DELETE api/zapier/hooks/{id}     unsubscribes it (when the Zap is turned off);
 *  - GET  api/zapier/samples/{event}  a made-up example, so the Zap editor can show fields without any real
 *                                     person's details leaving RightAlly.
 * Sending leads in stays POST api/leads. Each subscription is an ordinary outgoing webhook (signed, retried, shown
 * in Apps with "Zapier" in its name), so the broker can see and switch it off there. Events go out only when the
 * broker has turned on Outgoing webhooks.
 */
final class ZapierController
{
    public function me(Request $request, TenantContext $tenants): JsonResponse
    {
        $source = self::source($request);

        return response()->json(['brokerage' => $tenants->current()->name, 'key' => $source->name, 'events' => array_keys(Outbox::EVENTS),
            'events_on' => self::eventsOn()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $source = self::source($request);
        $data = $request->validate(['target_url' => ['required', 'string', 'max:500'], 'event' => ['required', 'string', 'in:'.implode(',', array_keys(Outbox::EVENTS))]]);
        if (! self::eventsOn()) {
            return response()->json(['message' => 'Ask your broker to turn on "Outgoing webhooks" in RightAlly (Settings › Apps) first.'], 409);
        }
        if (Connection::query()->where('app_key', 'webhooks')->whereNotIn('status', ['disconnected'])->count() >= 50) {
            return response()->json(['message' => 'This brokerage already has 50 webhooks. Remove some in Settings › Apps.'], 409);
        }
        try {
            $target = SafeUrl::check($data['target_url']);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'That address cannot receive webhooks.', 'errors' => $e->errors()], 422);
        }
        $c = Connection::query()->create(['app_key' => 'webhooks', 'owner_user_id' => null, 'label' => mb_substr('Zapier: '.$source->name.' ('.$data['event'].')', 0, 120),
            'status' => 'connected', 'credentials' => ['secret' => 'whsec_'.Str::random(40)],
            'settings' => ['url' => $target['url'], 'events' => [$data['event']], 'zapier_source' => $source->id], 'failures' => 0, 'connected_by' => (int) $source->getAttribute('created_by')]);
        app(AuditLogger::class)->record('connect.zapier.subscribed', metadata: ['connection' => $c->ulid, 'event' => $data['event'], 'host' => $target['host'], 'key' => $source->name],
            severity: 'notice');

        return response()->json(['id' => $c->ulid], 201);
    }

    public function unsubscribe(Request $request, string $hook): JsonResponse
    {
        $source = self::source($request);
        $c = Connection::query()->where('ulid', $hook)->where('app_key', 'webhooks')->first();
        if ($c === null || (int) ($c->settings['zapier_source'] ?? 0) !== $source->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $c->delete();
        app(AuditLogger::class)->record('connect.zapier.unsubscribed', metadata: ['connection' => $hook, 'key' => $source->name]);

        return response()->json(null, 204);
    }

    public function sample(Request $request, string $event): JsonResponse
    {
        self::source($request);
        abort_unless(isset(Outbox::EVENTS[$event]), 404);
        $agent = ['id' => 'agent_sample', 'name' => 'Sam Example', 'email' => 'sam@example.com'];
        $contact = ['id' => '01HSAMPLECONTACT0000000000', 'first_name' => 'Alex', 'last_name' => 'Sample', 'email' => 'alex@example.com', 'phone' => '+15555550100',
            'stage' => 'new'];
        $data = match ($event) {
            'lead.created' => ['lead' => ['id' => '01HSAMPLELEAD000000000000', 'source' => 'Website', 'channel' => 'form', 'intent' => 'buy', 'zip' => '78701',
                'price_min' => 300000, 'price_max' => 450000, 'property' => '12 Example St', 'message' => 'I would like to see this home.',
                'landing_url' => 'https://example.com/homes', 'created_at' => now()->toIso8601String()], 'agent' => $agent, 'contact' => $contact],
            'contact.stage_changed' => ['contact' => $contact, 'from' => 'new', 'to' => 'nurture'],
            'booking.created' => ['booking' => ['id' => '01HSAMPLEBOOKING00000000000', 'kind' => 'call', 'starts_at' => now()->addDay()->toIso8601String()],
                'agent' => $agent, 'contact' => $contact],
            'task.completed' => ['task' => ['id' => 1, 'title' => 'Call Alex back', 'completed_at' => now()->toIso8601String()], 'contact' => $contact],
            default => ['contact' => $contact],
        };

        return response()->json([['id' => 'sample-'.$event, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data]]);
    }

    private static function source(Request $request): LeadSource
    {
        $source = LeadSources::forKey((string) $request->bearerToken());
        abort_if($source === null, response()->json(['message' => 'Unknown or disabled API key.'], 401));

        return $source;
    }

    private static function eventsOn(): bool
    {
        $app = app(AppCatalog::class)->find('webhooks');

        return $app !== null && app(AppRegistry::class)->enabled($app);
    }
}
