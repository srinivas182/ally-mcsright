<?php

declare(strict_types=1);

namespace Modules\Connect\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Connect\AppRegistry;
use Modules\Connect\Connections;
use Modules\Connect\Models\SyncRun;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Which apps this person has connected to RightAlly (CRMs, transaction apps, calendars, accounting, marketing, phones…), each with its status, last sync, what came over and any error; and which apps they could connect, with the page to connect them. Use it when they mention another app, or before acting in one.')]
#[IsReadOnly]
final class ConnectedApps extends RightAllyTool
{
    protected string $name = 'connected_apps';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->description('Optional: one app\'s name or key, e.g. "GoHighLevel", "dotloop", "QuickBooks"')];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $catalog = app(AppCatalog::class);
        $want = mb_strtolower(trim((string) $request->get('app', '')));
        $match = fn (App $a): bool => $want === '' || $a->key === $want || str_contains(mb_strtolower($a->name), $want) || str_contains(str_replace(' ', '', mb_strtolower($a->name)), str_replace(' ', '', $want));
        $connected = [];
        foreach (app(Connections::class)->visible($user) as $c) {
            $app = $catalog->find($c->app_key);
            if ($app === null || ! $match($app)) {
                continue;
            }
            $run = SyncRun::query()->where('connection_id', $c->id)->latest('id')->first();
            $connected[] = ['app' => $app->name, 'key' => $app->key, 'category' => $app->category, 'whose' => $c->owner_user_id === null ? 'brokerage' : 'yours',
                'status' => $c->status, 'account' => $c->label, 'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'error' => $c->last_error,
                'last_run' => $run instanceof SyncRun ? ['created' => $run->created, 'updated' => $run->updated, 'note' => $run->note] : null,
                'page' => $app->link ?? '/apps/'.$app->key];
        }
        $have = array_column($connected, 'key');
        $could = [];
        foreach ($catalog->all() as $app) {
            if (! $match($app) || in_array($app->key, $have, true) || app(Connections::class)->ownerFor($app, $user) === false) {
                continue;
            }
            $state = ! $app->available() ? 'coming later' : (app(AppRegistry::class)->ready($app) ? 'ready to connect' : 'not switched on by RightAlly yet');
            $could[] = ['app' => $app->name, 'key' => $app->key, 'category' => $app->category, 'state' => $state, 'connect_page' => '/apps/'.$app->key];
        }

        return ['connected' => $connected, 'not_connected' => $want === '' ? [] : array_slice($could, 0, 5),
            'note' => $connected === [] && $want !== '' ? 'Not connected. Offer to connect it.' : null];
    }
}
