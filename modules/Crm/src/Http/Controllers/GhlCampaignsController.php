<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Connect\GhlCampaigns;
use Modules\Crm\Models\GhlEnrolment;
use Modules\Crm\Models\Tag;
use Modules\Crm\SmartLists;
use Throwable;

/**
 * Settings → GoHighLevel campaigns (specs/connect/15, C7a-2): which RightAlly contacts go into which GoHighLevel
 * workflow, campaign or trigger tag; pause or resume. Owners and staff for the brokerage's connection, agents for
 * their own.
 */
final class GhlCampaignsController
{
    public function index(Request $request, ConnectorKit $kit, GhlCampaigns $campaigns, SmartLists $lists): Response
    {
        $user = self::user($request);
        $connections = [];
        foreach ($kit->connections('gohighlevel') as $c) {
            if (! self::mine($c, $user)) {
                continue;
            }
            try {
                [$targets, $error] = [$campaigns->targets($c), null];
            } catch (NeedsReconnect $e) {
                [$targets, $error] = [['workflows' => [], 'campaigns' => []], $e->getMessage()];
            } catch (Throwable) {
                [$targets, $error] = [['workflows' => [], 'campaigns' => []], 'GoHighLevel didn\'t answer just now. Try again in a minute.'];
            }
            $connections[] = ['ulid' => $c->ulid, 'brokerage' => $c->ownerUserId === null, 'targets' => $targets, 'error' => $error,
                'enrolments' => GhlEnrolment::query()->where('connection_id', $c->id)->orderByDesc('id')->get()->map(fn (GhlEnrolment $e): array => [
                    'ulid' => $e->ulid, 'name' => $e->name, 'target_type' => $e->target_type, 'target_name' => $e->target_name, 'source_type' => $e->source_type,
                    'source_value' => $e->source_value, 'channel' => $e->channel, 'active' => $e->active, 'enrolled' => $e->enrolled, 'left_out' => $e->left_out,
                    'last_run_at' => $e->last_run_at?->toIso8601String(), 'last_error' => $e->last_error])->values()->all()];
        }

        return Inertia::render('settings/ghl-campaigns', ['connections' => $connections, 'tags' => Tag::query()->orderBy('name')->limit(300)->pluck('name')->all(),
            'lists' => array_map(fn (array $l): array => ['key' => $l['key'], 'name' => $l['name'], 'count' => $l['count']], $lists->listsFor($user))]);
    }

    public function store(Request $request, string $connection, ConnectorKit $kit, GhlCampaigns $campaigns): RedirectResponse
    {
        $user = self::user($request);
        $c = self::find($kit, $connection, $user);
        $e = $campaigns->create($c, $user, $request->only(['name', 'target_type', 'target_id', 'source_type', 'source_value', 'channel']));
        $added = $campaigns->run($c, $e);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Set up. {$added} added to \"{$e->target_name}\" now; new matches go in every hour."]);

        return back();
    }

    public function toggle(Request $request, string $enrolment, ConnectorKit $kit): RedirectResponse
    {
        $user = self::user($request);
        $e = GhlEnrolment::query()->where('ulid', $enrolment)->firstOrFail();
        $c = $kit->connection($e->connection_id);
        abort_unless($c !== null && self::mine($c, $user), 404);
        $e->forceFill(['active' => ! $e->active, 'last_error' => null])->save();
        app(AuditLogger::class)->record($e->active ? 'connect.ghl.enrolment_resumed' : 'connect.ghl.enrolment_paused', $e, label: $e->name, severity: 'notice');

        return back();
    }

    private static function find(ConnectorKit $kit, string $ulid, User $user): ConnectionData
    {
        foreach ($kit->connections('gohighlevel') as $c) {
            if ($c->ulid === $ulid && self::mine($c, $user)) {
                return $c;
            }
        }
        abort(404);
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
