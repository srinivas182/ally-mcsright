<?php

declare(strict_types=1);

namespace Modules\Connect;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Jobs\SyncConnection;
use Modules\Connect\Models\Connection;
use Modules\Connect\Webhooks\WebhookOutbox;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\Outbox;
use Modules\Core\Connect\ReceivesWebhooks;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections as Databases;
use Modules\Core\Support\SafeUrl;
use Modules\Core\Tenancy\TenantContext;

/**
 * Connecting and disconnecting apps (specs/connect/01).
 *
 * Who: brokerage apps are connected by owners and staff for everyone; agent apps by each person for themselves
 * ("both" apps: owners and staff connect the brokerage's, agents their own). Nobody sees another person's
 * credentials, and credentials never leave the server.
 */
final class Connections
{
    public const MANAGERS = [UserKind::Owner, UserKind::Staff];

    public function __construct(private readonly AppCatalog $catalog, private readonly TenantContext $tenants) {}

    /** Who connects apps for the whole brokerage (specs/core/03, R1): owners, staff without a role, or "Connect apps for the brokerage". */
    public static function manager(User $user): bool
    {
        return Can::manage($user, 'apps.manage');
    }

    public function app(string $key): App
    {
        return $this->catalog->find($key) ?? abort(404);
    }

    /** Whose connection this person would make for the app: null (the brokerage's), their own id, or not allowed. */
    public function ownerFor(App $app, User $user): int|false|null
    {
        $manager = self::manager($user);

        return match ($app->scope) {
            'brokerage' => $manager ? null : false,
            'agent' => in_array($user->kind, [...self::MANAGERS, UserKind::Agent], true) ? $user->id : false,
            default => $manager ? null : ($user->kind === UserKind::Agent ? $user->id : false),
        };
    }

    public function canManage(User $user, Connection $c): bool
    {
        return $c->owner_user_id === null ? self::manager($user) : $c->owner_user_id === $user->id;
    }

    /** @return list<Connection> the connections this person can see and manage */
    public function visible(User $user, ?string $appKey = null): array
    {
        $q = Connection::query()->when($appKey !== null, fn ($q) => $q->where('app_key', $appKey))->orderBy('id');
        $q = self::manager($user)
            ? $q->where(fn ($w) => $w->whereNull('owner_user_id')->orWhere('owner_user_id', $user->id))
            : $q->where('owner_user_id', $user->id);

        return array_values($q->get()->all());
    }

    public function find(string $ulid, User $user): Connection
    {
        $c = Connection::query()->where('ulid', $ulid)->first();
        abort_unless($c !== null && $this->canManage($user, $c), 404);

        return $c;
    }

    /**
     * Connects (or reconnects) an app. Returns the connection, and for webhooks the signing secret, shown once.
     *
     * @param  array<string, mixed>  $input
     * @return array{connection: Connection, secret: string|null}
     */
    public function connect(App $app, User $by, array $input): array
    {
        $owner = $this->ownerFor($app, $by);
        if ($owner === false) {
            throw ValidationException::withMessages(['app' => 'Your brokerage connects '.$app->name.' for everyone.']);
        }
        if (! app(AppRegistry::class)->ready($app) || ! in_array($app->auth, ['api_key', 'webhook'], true)) {
            throw ValidationException::withMessages(['app' => $app->name.' can\'t be connected here yet.']);
        }
        app(PaidApps::class)->require($app, $owner); // a paid app needs its add-on first (specs/connect/14)
        $credentials = [];
        $settings = [];
        $errors = [];
        foreach ($app->fields as $f) {
            $value = $input[$f['key']] ?? null;
            if ($f['type'] === 'events') {
                $events = array_values(array_intersect(array_map('strval', is_array($value) ? $value : []), array_keys(Outbox::EVENTS)));
                $events === [] ? $errors[$f['key']] = 'Choose at least one event.' : $settings[$f['key']] = $events;

                continue;
            }
            $text = is_scalar($value) ? trim((string) $value) : '';
            if ($f['type'] === 'checkbox') {
                $settings[$f['key']] = in_array($text, ['1', 'true', 'on'], true);

                continue;
            }
            if ($text === '') {
                $errors[$f['key']] = 'Enter the '.mb_strtolower($f['label']).'.';

                continue;
            }
            ($f['secret'] ?? false) ? $credentials[$f['key']] = mb_substr($text, 0, 500) : $settings[$f['key']] = mb_substr($text, 0, 500);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $secret = null;
        $label = is_string($input['label'] ?? null) ? trim(strip_tags($input['label'])) : '';
        if ($app->auth === 'webhook') {
            $target = SafeUrl::check(is_string($settings['url'] ?? null) ? $settings['url'] : '');
            $settings['url'] = $target['url'];
            $credentials['secret'] = $secret = 'whsec_'.Str::random(40);
            $label = $label !== '' ? $label : $target['host'];
        } elseif ($app->driver !== null) {
            $driver = app($app->driver);
            if ($driver instanceof Connector) {
                $label = $driver->verify($credentials, $settings);
            }
        }

        $existing = $app->multiple ? null : Connection::query()->where('app_key', $app->key)->where('owner_user_id', $owner)->first();
        $c = $existing ?? new Connection(['app_key' => $app->key, 'owner_user_id' => $owner]);
        $c->fill(['label' => mb_substr($label !== '' ? $label : $app->name, 0, 120), 'status' => 'connected', 'credentials' => $credentials, 'settings' => $settings,
            'failures' => 0, 'last_error' => null, 'connected_by' => $by->id])->save();
        app(AuditLogger::class)->record('connect.connected', metadata: ['app' => $app->key, 'connection' => $c->ulid, 'for' => $owner === null ? 'brokerage' : 'self'], severity: 'notice');
        if ($app->driver !== null && $app->auth !== 'webhook') {
            SyncConnection::dispatch($c->id);
        }

        return ['connection' => $c, 'secret' => $secret];
    }

    public function disconnect(Connection $c, User $by): void
    {
        $app = $this->catalog->find($c->app_key);
        $driver = $app?->driver !== null ? app($app->driver) : null;
        if ($driver instanceof ReceivesWebhooks) {
            $driver->disconnected($c->data()); // switch off the other app's webhooks while the sign-in still works
        }
        if ($app !== null && $app->oauth !== null) {
            app(OAuth\OAuthFlow::class)->revoke($c, $app);
        }
        $c->delete(); // its credentials go with it
        app(AuditLogger::class)->record('connect.disconnected', metadata: ['app' => $c->app_key, 'connection' => $c->ulid], severity: 'notice');
    }

    public function syncNow(Connection $c): void
    {
        if (in_array($c->status, ['needs_reconnect', 'paused'], true)) {
            throw ValidationException::withMessages(['sync' => 'Connect it again first.']);
        }
        SyncConnection::dispatch($c->id);
    }

    /** Sends a "ping" event to one webhook endpoint. */
    public function ping(Connection $c): void
    {
        abort_unless($c->app_key === 'webhooks', 404);
        app(WebhookOutbox::class)->queue($c, 'ping', ['message' => 'Hello from RightAlly. Your endpoint is connected.', 'brokerage' => $this->tenants->current()->name]);
    }

    public function pause(Connection $c, bool $paused): void
    {
        $c->forceFill(['status' => $paused ? 'paused' : 'connected'])->save();
    }

    /** A brokerage asks for an app that is coming: HQ sees the demand (specs/connect/00). */
    public function request(App $app, User $by): void
    {
        $db = DB::connection(Databases::control())->table('hq_app_requests');
        $row = $db->where('tenant_id', $this->tenants->id())->where('app_key', $app->key)->first();
        $row === null
            ? DB::connection(Databases::control())->table('hq_app_requests')->insert(['tenant_id' => $this->tenants->id(), 'app_key' => $app->key, 'requests' => 1,
                'last_requested_at' => now(), 'created_at' => now(), 'updated_at' => now()])
            : DB::connection(Databases::control())->table('hq_app_requests')->where('id', $row->id)->update(['requests' => (int) $row->requests + 1, 'last_requested_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('connect.app_requested', metadata: ['app' => $app->key], severity: 'info');
    }

    /** @return array<string, int> app key → this brokerage's requests */
    public function requested(): array
    {
        return DB::connection(Databases::control())->table('hq_app_requests')->where('tenant_id', $this->tenants->id())->pluck('requests', 'app_key')
            ->map(fn ($n): int => (int) $n)->all();
    }
}
