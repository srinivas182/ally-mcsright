<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Crm\Consent;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Inbox\Bridges;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\GhlEnrolment;
use Modules\Crm\SmartLists;

/**
 * GoHighLevel campaigns (specs/connect/15, C7a-2). The campaign is built once in GoHighLevel; RightAlly chooses who
 * goes in (a tag or a smart list) and keeps adding new matches every hour: into a workflow, a campaign, or by a
 * trigger tag. GoHighLevel's public API adds contacts to workflows and campaigns but doesn't create them.
 *
 * - Consent first: people who opted out of the channel the campaign uses (email, texts, or either) never go; texts
 *   need written consent with proof (specs/grow/11).
 * - Same visibility as the screens: the person who set it up must be able to see each contact; a brokerage
 *   connection never sends agents' personal contacts.
 * - Each person goes in once per enrolment. 100 a run.
 */
final class GhlCampaigns
{
    public const BATCH = 100;

    private const VERSION = '2021-07-28';

    public function __construct(private readonly ConnectorKit $kit, private readonly Consent $consent) {}

    /** @return array{workflows: list<array{id: string, name: string}>, campaigns: list<array{id: string, name: string}>} */
    public function targets(ConnectionData $connection): array
    {
        $location = (string) ($connection->settings['location_id'] ?? '');
        $out = ['workflows' => [], 'campaigns' => []];
        foreach (['workflows' => 'workflows/', 'campaigns' => 'campaigns/'] as $kind => $path) {
            $r = self::check($this->http($connection)->get($path, ['locationId' => $location]));
            foreach ((array) $r->json($kind, []) as $t) {
                if (is_array($t) && is_string($t['id'] ?? null)) {
                    $out[$kind][] = ['id' => $t['id'], 'name' => (string) ($t['name'] ?? $t['id'])];
                }
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $in  name, target_type, target_id, source_type, source_value, channel */
    public function create(ConnectionData $connection, User $by, array $in): GhlEnrolment
    {
        $type = (string) ($in['target_type'] ?? '');
        $targetId = trim((string) ($in['target_id'] ?? ''));
        $targetName = $targetId;
        if (in_array($type, ['workflow', 'campaign'], true)) {
            $found = collect($this->targets($connection)[$type.'s'])->firstWhere('id', $targetId);
            if ($found === null) {
                throw ValidationException::withMessages(['target_id' => 'Choose one of your GoHighLevel '.$type.'s.']);
            }
            $targetName = $found['name'];
        } elseif ($type !== 'tag' || $targetId === '' || mb_strlen($targetId) > 60) {
            throw ValidationException::withMessages(['target_id' => 'Choose a workflow or campaign, or name the tag that starts your GoHighLevel automation.']);
        }
        $sourceType = (string) ($in['source_type'] ?? '');
        $sourceValue = trim((string) ($in['source_value'] ?? ''));
        if (! ($sourceType === 'tag' && $sourceValue !== '') && ! ($sourceType === 'smart_list' && app(SmartLists::class)->filtersFor($sourceValue, $by) !== null)) {
            throw ValidationException::withMessages(['source_value' => 'Choose a tag or a smart list.']);
        }
        $channel = in_array($in['channel'] ?? null, ['email', 'sms', 'any'], true) ? (string) $in['channel'] : 'email';
        $name = trim(strip_tags((string) ($in['name'] ?? ''))) ?: $targetName;
        $e = GhlEnrolment::query()->create(['connection_id' => $connection->id, 'name' => mb_substr($name, 0, 120), 'target_type' => $type, 'target_id' => mb_substr($targetId, 0, 100),
            'target_name' => mb_substr($targetName, 0, 150), 'source_type' => $sourceType, 'source_value' => mb_substr($sourceValue, 0, 100), 'channel' => $channel, 'active' => true,
            'created_by' => $by->id]);
        app(AuditLogger::class)->record('connect.ghl.enrolment_created', $e, metadata: ['target' => $type, 'source' => $sourceType, 'channel' => $channel], label: $e->name, severity: 'notice');

        return $e;
    }

    /** This person's GoHighLevel connection (the brokerage's for owners and staff, else their own), or null. */
    public function connectionFor(User $user): ?ConnectionData
    {
        $manager = in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
        $mine = null;
        foreach ($this->kit->connections('gohighlevel') as $c) {
            if ($c->ownerUserId === $user->id) {
                $mine = $c;
            } elseif ($c->ownerUserId === null && $manager) {
                return $c;
            }
        }

        return $mine;
    }

    /**
     * What adding these people would do, before anything is sent (for "Your AI Assistant" and previews).
     *
     * @param  array<string, mixed>  $in  source_type, source_value, channel
     * @return array{matching: int, will_add: int, left_out: int}
     */
    public function preview(ConnectionData $connection, User $by, array $in): array
    {
        $channel = in_array($in['channel'] ?? null, ['email', 'sms', 'any'], true) ? (string) $in['channel'] : 'email';
        $q = $this->candidates($connection, $by, (string) ($in['source_type'] ?? ''), (string) ($in['source_value'] ?? ''), []);
        if ($q === null) {
            throw ValidationException::withMessages(['source_value' => 'Choose a tag or a smart list.']);
        }
        $all = $q->limit(5000)->get();
        $ok = $all->filter(fn (Contact $c): bool => $this->allowed($c, $channel))->count();

        return ['matching' => $all->count(), 'will_add' => $ok, 'left_out' => $all->count() - $ok];
    }

    /**
     * @param  list<int>  $exclude
     * @return Builder<Contact>|null
     */
    private function candidates(ConnectionData $connection, User $by, string $sourceType, string $sourceValue, array $exclude): ?Builder
    {
        $q = app(ContactVisibility::class)->query($by)->whereNotIn('id', $exclude)
            ->when($connection->ownerUserId === null, fn ($q) => $q->where('origin', '!=', Origin::Personal->value), fn ($q) => $q->where('owner_user_id', $connection->ownerUserId));
        if ($sourceType === 'tag' && $sourceValue !== '') {
            return $q->whereHas('tags', fn ($t) => $t->where('name', $sourceValue));
        }
        $filters = $sourceType === 'smart_list' ? app(SmartLists::class)->filtersFor($sourceValue, $by) : null;

        return $filters === null ? null : app(SmartLists::class)->apply($q, $filters);
    }

    /** Runs every active enrolment for this connection. Returns the people added. */
    public function runAll(ConnectionData $connection): int
    {
        $n = 0;
        foreach (GhlEnrolment::query()->where('connection_id', $connection->id)->where('active', true)->orderBy('id')->get() as $e) {
            $n += $this->run($connection, $e);
        }

        return $n;
    }

    public function run(ConnectionData $connection, GhlEnrolment $e): int
    {
        $by = User::query()->find($e->created_by);
        if (! $by instanceof User || ! $by->isActive()) {
            $e->forceFill(['active' => false, 'last_error' => 'Paused: the person who set this up no longer has access.'])->save();

            return 0;
        }
        $db = DB::connection(Connections::cell());
        $done = array_values(array_map('intval', $db->table('crm_ghl_enrolled')->where('enrolment_id', $e->id)->pluck('contact_id')->all()));
        $q = $this->candidates($connection, $by, $e->source_type, $e->source_value, $done);
        if ($q === null) {
            $e->forceFill(['active' => false, 'last_error' => 'Paused: the smart list was removed.'])->save();

            return 0;
        }
        $added = 0;
        $left = 0;
        try {
            foreach ($q->orderBy('id')->limit(self::BATCH * 3)->get() as $c) {
                if ($added >= self::BATCH) {
                    break;
                }
                if (! $this->allowed($c, $e->channel) || ! app(Bridges::class)->allowsBulk($c)) {
                    $left++;

                    continue;
                }
                $this->enrol($connection, $e, $this->ghlId($connection, $c));
                $db->table('crm_ghl_enrolled')->insertOrIgnore(['tenant_id' => $e->tenant_id, 'enrolment_id' => $e->id, 'contact_id' => $c->id, 'created_at' => CarbonImmutable::now()]);
                $added++;
            }
            $e->forceFill(['enrolled' => $e->enrolled + $added, 'left_out' => $left, 'last_run_at' => CarbonImmutable::now(), 'last_error' => null])->save();
        } catch (NeedsReconnect $x) {
            throw $x;
        } catch (\Throwable $x) {
            $e->forceFill(['enrolled' => $e->enrolled + $added, 'last_run_at' => CarbonImmutable::now(), 'last_error' => mb_substr($x->getMessage(), 0, 300)])->save();
        }

        return $added;
    }

    /** May this person get this campaign's messages? Opt-outs never; texts need written consent with proof. */
    private function allowed(Contact $c, string $channel): bool
    {
        $emailOk = $c->primary_email !== null && ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action') !== 'opt_out';
        $smsOk = $this->consent->canAutoText($c);

        return match ($channel) {
            'sms' => $smsOk,
            'any' => $emailOk || $smsOk,
            default => $emailOk,
        };
    }

    /** Their GoHighLevel contact: the one we know (imported or sent before), or added now by email or phone. */
    private function ghlId(ConnectionData $connection, Contact $c): string
    {
        $known = $this->kit->externalId($connection, 'contact', $c->id);
        if ($known !== null) {
            return $known;
        }
        $r = self::check($this->http($connection)->post('contacts/upsert', array_filter(['locationId' => (string) ($connection->settings['location_id'] ?? ''),
            'firstName' => $c->first_name, 'lastName' => $c->last_name, 'email' => $c->primary_email, 'phone' => $c->primary_phone, 'source' => 'RightAlly'], fn ($v) => $v !== null && $v !== '')));
        $id = (string) $r->json('contact.id');
        if ($id === '') {
            throw new \RuntimeException('GoHighLevel didn\'t return the contact.');
        }
        $this->kit->remember($connection, 'contact', $id, $c->id);

        return $id;
    }

    private function enrol(ConnectionData $connection, GhlEnrolment $e, string $ghlId): void
    {
        $http = $this->http($connection);
        self::check(match ($e->target_type) {
            'workflow' => $http->post("contacts/{$ghlId}/workflow/{$e->target_id}", ['eventStartTime' => CarbonImmutable::now()->toIso8601String()]),
            'campaign' => $http->post("contacts/{$ghlId}/campaigns/{$e->target_id}"),
            default => $http->post("contacts/{$ghlId}/tags", ['tags' => [$e->target_id]]),
        });
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        return Http::withToken((string) ($connection->credentials['api_key'] ?? ''))->withHeaders(['Version' => self::VERSION])->acceptJson()->asJson()->timeout(20)
            ->baseUrl(GoHighLevel::API);
    }

    private static function check(Response $r): Response
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('GoHighLevel didn\'t accept the token for campaigns. Make a new Private Integration token with "Edit Contacts", "View Workflows" and "View Campaigns", and connect again.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException('GoHighLevel refused: '.mb_substr((string) ($r->json('message') ?? $r->body()), 0, 200));
        }

        return $r;
    }
}
