<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Connect\AppRegistry;
use Modules\Connect\Connections;
use Modules\Connect\Mcp\AiAssistants;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Connect\PaidApps;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Outbox;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\LeadSource;

/** The Apps page (specs/connect/01): the catalog, one app's screen, connect, disconnect, sync, test, request. */
final class AppsController
{
    public function __construct(private readonly Connections $connections, private readonly AppCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $mine = collect($this->connections->visible($user))->groupBy('app_key');
        // AI assistants connect with sign-in tokens, not connections: count the person's own per AI app.
        $ai = collect(app(AiAssistants::class)->connected($user))->where('mine', true)->countBy('app');
        $requested = $this->connections->requested();

        return Inertia::render('apps/index', [
            'categories' => App::CATEGORIES,
            'apps' => array_map(fn (App $a): array => [...$a->toArray(), 'connected' => $a->category === 'ai' ? (int) ($ai[$a->key] ?? 0) : ($mine->get($a->key)?->count() ?? 0),
                'problem' => (bool) $mine->get($a->key)?->contains(fn (Connection $c): bool => in_array($c->status, ['error', 'needs_reconnect'], true)),
                'can_connect' => $this->connections->ownerFor($a, $user) !== false, 'requested' => isset($requested[$a->key]), 'ready' => self::ready($a), 'status' => self::ready($a) ? 'available' : 'soon',
                'price_cents' => app(AppRegistry::class)->price($a)['cents'] ?? null], $this->catalog->all()),
        ]);
    }

    public function show(Request $request, string $app): Response
    {
        $user = self::user($request);
        $a = $this->connections->app($app);
        $connections = $this->connections->visible($user, $a->key);
        $ids = array_map(fn (Connection $c): int => $c->id, $connections);
        $runs = SyncRun::query()->whereIn('connection_id', $ids)->latest('id')->limit(20)->get();
        $deliveries = $a->key === 'webhooks' ? WebhookDelivery::query()->whereIn('connection_id', $ids)->latest('id')->limit(30)->get() : collect();

        return Inertia::render('apps/show', [
            'app' => [...$a->toArray(), 'can_connect' => $this->connections->ownerFor($a, $user) !== false, 'requested' => isset($this->connections->requested()[$a->key]),
                'ready' => self::ready($a), 'status' => self::ready($a) ? 'available' : 'soon', 'price' => app(PaidApps::class)->state($a, $user, $this->connections->ownerFor($a, $user))],
            'connections' => array_map(fn (Connection $c): array => ['ulid' => $c->ulid, 'label' => $c->label, 'status' => $c->status, 'mine' => $c->owner_user_id !== null,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'last_error' => $c->last_error, 'created_at' => $c->created_at->toIso8601String(),
                'url' => $a->key === 'webhooks' ? (string) ($c->settings['url'] ?? '') : null, 'events' => $a->key === 'webhooks' ? array_values((array) ($c->settings['events'] ?? [])) : [],
                'runs' => $runs->where('connection_id', $c->id)->take(5)->map(fn (SyncRun $r): array => ['status' => $r->status, 'created' => $r->created, 'updated' => $r->updated,
                    'skipped' => $r->skipped, 'note' => $r->note, 'at' => $r->started_at->toIso8601String()])->values()->all(),
                'deliveries' => $deliveries->where('connection_id', $c->id)->take(15)->map(fn (WebhookDelivery $d): array => ['ulid' => $d->ulid, 'event' => $d->event,
                    'status' => $d->status, 'attempts' => $d->attempts, 'code' => $d->response_code, 'error' => $d->last_error, 'at' => $d->created_at->toIso8601String()])->values()->all()],
                $connections),
            'events' => Outbox::EVENTS,
            'secret' => $request->session()->get('connect.secret'),
            'help' => self::help($a, $user),
            'mcp' => $a->category === 'ai' ? ['address' => url('/mcp'), 'settings' => app(AiAssistants::class)->settings(),
                'allowed' => app(AiAssistants::class)->allowed($user), 'connected' => app(AiAssistants::class)->connected($user), 'is_owner' => $user->kind === UserKind::Owner] : null,
        ]);
    }

    public function connect(Request $request, string $app): RedirectResponse
    {
        $user = self::user($request);
        $done = $this->connections->connect($this->connections->app($app), $user, $request->all());
        if ($done['secret'] !== null) {
            $request->session()->flash('connect.secret', ['connection' => $done['connection']->ulid, 'secret' => $done['secret']]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $done['connection']->label.' is connected.']);

        return back();
    }

    /** Adds a paid app's monthly add-on (charged now in credits), so it can be connected (specs/connect/14). */
    public function addAddon(Request $request, string $app): RedirectResponse
    {
        $user = self::user($request);
        $a = $this->connections->app($app);
        $owner = $this->connections->ownerFor($a, $user);
        abort_if($owner === false, 403);
        app(PaidApps::class)->buy($a, $user, $owner, $request->input('pay_with') === 'card' ? 'card' : 'credits');
        Inertia::flash('toast', ['type' => 'success', 'message' => $a->name.' is added. You can connect it now.']);

        return back();
    }

    /** Switches a paid app's add-on between RightAlly credits and the saved card, from the next month. */
    public function addonPayWith(Request $request, string $app): RedirectResponse
    {
        $user = self::user($request);
        $a = $this->connections->app($app);
        $owner = $this->connections->ownerFor($a, $user);
        abort_if($owner === false, 403);
        $data = $request->validate(['pay_with' => ['required', 'in:credits,card']]);
        app(PaidApps::class)->payWith($a, $user, $owner, (string) $data['pay_with']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. From next month '.$a->name.' is paid with '.($data['pay_with'] === 'card' ? 'your card.' : 'RightAlly credits.')]);

        return back();
    }

    /** Cancels a paid app's add-on at the end of the paid month. */
    public function cancelAddon(Request $request, string $app): RedirectResponse
    {
        $user = self::user($request);
        $a = $this->connections->app($app);
        $owner = $this->connections->ownerFor($a, $user);
        abort_if($owner === false, 403);
        app(PaidApps::class)->cancel($a, $user, $owner);
        Inertia::flash('toast', ['type' => 'success', 'message' => $a->name.' stays on until the end of the month you paid for, then pauses.']);

        return back();
    }

    public function disconnect(Request $request, string $connection): RedirectResponse
    {
        $user = self::user($request);
        $this->connections->disconnect($this->connections->find($connection, $user), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Disconnected. Its saved sign-in details were deleted.']);

        return back();
    }

    public function sync(Request $request, string $connection): RedirectResponse
    {
        $this->connections->syncNow($this->connections->find($connection, self::user($request)));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sync started.']);

        return back();
    }

    public function ping(Request $request, string $connection): RedirectResponse
    {
        $this->connections->ping($this->connections->find($connection, self::user($request)));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test event sent. Its result appears below.']);

        return back();
    }

    public function pause(Request $request, string $connection): RedirectResponse
    {
        $paused = (bool) $request->validate(['paused' => ['required', 'boolean']])['paused'];
        $this->connections->pause($this->connections->find($connection, self::user($request)), $paused);

        return back();
    }

    public function request(Request $request, string $app): RedirectResponse
    {
        $this->connections->request($this->connections->app($app), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks! We\'ve noted that you want this app.']);

        return back();
    }

    /**
     * What the built-in apps need to show: the brokerage's forwarding address and lead API sources.
     *
     * @return array<string, mixed>
     */
    private static function help(App $a, User $user): array
    {
        if ((! in_array($a->key, ['lead_email', 'lead_api', 'zapier'], true) && $a->auth !== 'email') || ! in_array($user->kind, Connections::MANAGERS, true)) {
            return [];
        }

        return ['emails' => LeadSource::query()->where('kind', 'email')->where('active', true)->orderBy('name')->get()
            ->map(fn (LeadSource $s): array => ['name' => $s->name, 'address' => LeadSources::address($s)])->values()->all(),
            'api_sources' => LeadSource::query()->where('kind', 'api')->where('active', true)->orderBy('name')->get()
                ->map(fn (LeadSource $s): array => ['name' => $s->name, 'last4' => (string) $s->key_last4])->values()->all(),
            'api_url' => url('/api/leads'), 'settings_url' => '/leads/settings'];
    }

    /** Built, switched on by HQ, and set up (specs/connect/00). */
    private static function ready(App $a): bool
    {
        return app(AppRegistry::class)->ready($a);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
