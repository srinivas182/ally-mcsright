<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Connect\AppRegistry;
use Modules\Connect\Jobs\HandleWebhook;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\AppWebhooks;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * One webhook address per app, for apps that send every account's events to one place (Meta):
 * `https://{core.sso_host}/connect/apps/{app}/webhook` (specs/connect/10). The signature is checked first; each part
 * then goes only to the brokerage connection that owns that account (connect_routes), as a queued job.
 */
#[CrossTenantAccess('App-level webhooks are split by account and handed only to the brokerage that connected that account')]
final class AppWebhookController
{
    private const MAX_BYTES = 524288;

    public function handshake(Request $request, string $app, AppCatalog $catalog): Response
    {
        $driver = self::driver($app, $catalog);

        return $driver?->handshake($request->query()) ?? response('', 404);
    }

    public function receive(Request $request, string $app, AppCatalog $catalog, TenantContext $tenants): Response
    {
        $driver = self::driver($app, $catalog);
        $raw = $request->getContent();
        if ($driver === null || strlen($raw) > self::MAX_BYTES) {
            return response('', 404);
        }
        $headers = array_map(fn (array $v): string => (string) ($v[0] ?? ''), array_change_key_case($request->headers->all(), CASE_LOWER));
        if (! $driver->verifyWebhook($raw, $headers)) {
            return response('', 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('', 400);
        }
        foreach ($driver->route($payload) as $part) {
            $route = DB::connection(Connections::control())->table('connect_routes')->where('app_key', $app)->where('external_key', $part['key'])->first();
            $tenant = $route !== null ? Tenant::query()->whereKey($route->tenant_id)->where('status', 'active')->first() : null;
            if ($tenant === null) {
                continue; // an account no brokerage has connected (or no longer): ignored
            }
            $tenants->runAs($tenant, function () use ($route, $app, $part): void {
                $c = Connection::query()->whereKey($route->connection_id)->where('app_key', $app)->first();
                $a = app(AppCatalog::class)->find($app);
                if ($c !== null && $a !== null && in_array($c->status, ['connected', 'error'], true) && app(AppRegistry::class)->ready($a)) {
                    HandleWebhook::dispatch($c->id, $part['payload']);
                }
            });
        }

        return response('', 200); // always 200 once checked, so the app does not retry or switch the address off
    }

    private static function driver(string $app, AppCatalog $catalog): ?AppWebhooks
    {
        $a = $catalog->find($app);
        $driver = $a?->driver !== null ? app($a->driver) : null;

        return $driver instanceof AppWebhooks ? $driver : null;
    }
}
