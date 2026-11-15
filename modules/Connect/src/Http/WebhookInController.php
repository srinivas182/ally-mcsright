<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Connect\AppRegistry;
use Modules\Connect\Jobs\HandleWebhook;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ReceivesWebhooks;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Webhooks from connected apps (specs/connect/05), on the central host:
 * `/connect/hooks/{app}/{brokerage ulid}/{connection ulid}`. The address holds no secrets; every webhook must carry
 * the other app's signature, which is checked before anything is read. We answer within milliseconds and do the work
 * in a queued job. A removed connection answers 410, so the other app stops sending.
 */
#[CrossTenantAccess('One webhook address per connection; the brokerage is named in it and checked to be active')]
final class WebhookInController
{
    private const MAX_BYTES = 262144;

    public function __invoke(Request $request, string $app, string $tenant, string $connection, TenantContext $tenants, AppCatalog $catalog): Response
    {
        $a = $catalog->find($app);
        $driver = $a?->driver !== null ? app($a->driver) : null;
        $raw = $request->getContent();
        if ($a === null || ! $driver instanceof ReceivesWebhooks || strlen($raw) > self::MAX_BYTES) {
            return response('', 404);
        }
        $headers = array_map(fn (array $v): string => (string) ($v[0] ?? ''), array_change_key_case($request->headers->all(), CASE_LOWER));
        if (! $driver->verifyWebhook($raw, $headers)) {
            return response('', 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) && str_starts_with((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($raw, $payload); // Mailchimp posts forms
        }
        $t = Tenant::query()->where('ulid', $tenant)->where('status', 'active')->first();
        if (! is_array($payload) || $t === null) {
            return response('', $t === null ? 410 : 400);
        }

        return $tenants->runAs($t, function () use ($a, $connection, $payload): Response {
            $c = Connection::query()->where('ulid', $connection)->where('app_key', $a->key)->first();
            if ($c === null) {
                return response('', 410); // disconnected: the other app removes the webhook
            }
            if (! in_array($c->status, ['connected', 'error'], true) || ! app(AppRegistry::class)->ready($a)) {
                return response('', 204); // paused: nothing is changed, and the other app does not retry
            }
            HandleWebhook::dispatch($c->id, $payload);

            return response('', 204);
        });
    }
}
