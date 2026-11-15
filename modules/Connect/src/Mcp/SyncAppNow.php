<?php

declare(strict_types=1);

namespace Modules\Connect\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Connect\Connections;
use Modules\Connect\Jobs\SyncConnection;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Sync a connected app now instead of waiting for the hourly run: bring deals, documents or contacts over, or send journals or contacts out (whatever that app does). Shows what will happen first.')]
final class SyncAppNow extends RightAllyTool
{
    protected string $name = 'sync_app_now';

    protected bool $writes = true;

    protected bool $confirmFirst = true;

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->description('The app\'s key or name, e.g. "dotloop", "gohighlevel", "quickbooks"')->required()];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<mixed> */
    protected function preview(Request $request, User $user): array
    {
        $c = $this->find($request, $user);
        $app = app(AppCatalog::class)->find($c->app_key);

        return ['action' => 'Sync '.($app->name ?? $c->app_key).' now', 'what' => $app !== null ? $app->syncs : [], 'whose' => $c->owner_user_id === null ? 'the brokerage\'s connection' : 'your connection'];
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $c = $this->find($request, $user);
        SyncConnection::dispatch($c->id);

        return ['started' => true, 'app' => app(AppCatalog::class)->find($c->app_key)->name ?? $c->app_key, 'note' => 'Syncing now. Results show on the app\'s page in a minute or two.'];
    }

    private function find(Request $request, User $user): Connection
    {
        $want = mb_strtolower(str_replace(' ', '', trim((string) $request->get('app', ''))));
        foreach (app(Connections::class)->visible($user) as $c) {
            $name = mb_strtolower(str_replace(' ', '', (string) (app(AppCatalog::class)->find($c->app_key)->name ?? '')));
            if ($want !== '' && ($c->app_key === $want || $name === $want || str_contains($name, $want)) && in_array($c->status, ['connected', 'error'], true)) {
                return $c;
            }
        }
        throw ValidationException::withMessages(['app' => 'That app isn\'t connected for you. Connect it under Apps first.']);
    }
}
