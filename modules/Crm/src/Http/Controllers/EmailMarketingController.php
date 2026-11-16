<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Connect\ConstantContact;
use Modules\Crm\Connect\EmailApp;
use Modules\Crm\Connect\Mailchimp;
use Throwable;

/**
 * Settings → Email marketing (specs/connect/15, C7a): for each connected Mailchimp or Constant Contact account,
 * choose the audience and who goes, and see how it's going. Owners and staff manage the brokerage's connection;
 * agents their own.
 */
final class EmailMarketingController
{
    private const APPS = ['mailchimp' => Mailchimp::class, 'constant_contact' => ConstantContact::class];

    public function index(Request $request, ConnectorKit $kit): Response
    {
        $user = self::user($request);
        $out = [];
        foreach (self::APPS as $key => $driver) {
            foreach ($kit->connections($key) as $c) {
                if (! self::mine($c, $user)) {
                    continue;
                }
                /** @var EmailApp $app */
                $app = app($driver);
                try {
                    [$audiences, $error] = [$app->audiences($c), null];
                } catch (NeedsReconnect $e) {
                    [$audiences, $error] = [[], $e->getMessage()];
                } catch (Throwable) {
                    [$audiences, $error] = [[], $app->name().' didn\'t answer just now. Try again in a minute.'];
                }
                $out[] = ['ulid' => $c->ulid, 'app' => $key, 'name' => $app->name(), 'brokerage' => $c->ownerUserId === null, 'audiences' => $audiences, 'error' => $error,
                    'audience' => $c->settings['audience'] ?? null, 'who' => $c->settings['who'] ?? 'consented', 'in_audience' => (int) ($c->settings['in_audience'] ?? 0),
                    'last_push_at' => $c->settings['last_push_at'] ?? null, 'unsubs_month' => (int) ($c->settings['unsubs_month'] ?? 0)];
            }
        }

        return Inertia::render('settings/email-marketing', ['connections' => $out]);
    }

    public function save(Request $request, string $connection, ConnectorKit $kit): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['audience' => ['required', 'string', 'max:64'], 'who' => ['required', 'in:consented,not_opted_out'], 'permission' => ['boolean']]);
        $c = null;
        foreach (array_keys(self::APPS) as $key) {
            foreach ($kit->connections($key) as $x) {
                $c = $x->ulid === $connection ? $x : $c;
            }
        }
        abort_unless($c instanceof ConnectionData && self::mine($c, $user), 404);
        if ($data['who'] === 'not_opted_out' && ! $request->boolean('permission')) {
            throw ValidationException::withMessages(['permission' => 'Confirm you have permission to email these people (Mailchimp and Constant Contact require it).']);
        }
        $changed = ($c->settings['audience'] ?? null) !== $data['audience'];
        $kit->saveSettings($c, ['audience' => $data['audience'], 'who' => $data['who']] + ($changed ? ['in_audience' => 0] : [])); // a new audience gets everyone again
        app(AuditLogger::class)->record('connect.email_marketing.saved', metadata: ['app' => $c->appKey, 'who' => $data['who'], 'audience_changed' => $changed], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Contacts go on the next run (every hour), or press "Sync now" on the app.']);

        return back();
    }

    private static function mine(ConnectionData $c, User $user): bool
    {
        return $c->ownerUserId === null ? Can::manage($user, 'marketing.manage') : $c->ownerUserId === $user->id;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
