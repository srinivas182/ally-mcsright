<?php

declare(strict_types=1);

namespace Modules\Api\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Models\Connection;
use Modules\Connect\Webhooks\SafeUrl;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\Outbox;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;

/**
 * Public API v1 (API1, specs/api/01). JSON in and out; lists are newest-changed first with a cursor; every call acts as
 * the key's or token's person and sees only what they see on screen. Writes accept an Idempotency-Key header (24 h).
 * Never offered: deletes, messaging many people, money movements, bank details or commission splits.
 */
final class V1Controller
{
    private const PAGE = 50;

    public function me(Request $request, TenantContext $tenants): JsonResponse
    {
        $u = self::user($request);

        return response()->json(['data' => ['user' => ['id' => $u->ulid, 'name' => (string) $u->name, 'email' => $u->email, 'kind' => $u->kind->value],
            'brokerage' => ['id' => $tenants->current()->ulid, 'name' => $tenants->current()->name], 'scopes' => $request->attributes->get('api_scopes')]]);
    }

    public function contacts(Request $request, ContactVisibility $visibility): JsonResponse
    {
        self::need($request, 'contacts:read');
        $v = self::check($request->query(), ['updated_since' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $q = $visibility->query(self::user($request));
        if (($v['updated_since'] ?? null) !== null) {
            $q->where('updated_at', '>=', CarbonImmutable::parse((string) $v['updated_since']));
        }
        if (($v['q'] ?? '') !== '') {
            $q->where('search_name', 'like', '%'.mb_strtolower((string) $v['q']).'%');
        }

        return self::page($q, $request, fn (Contact $c): array => self::contactJson($c), (int) ($v['limit'] ?? self::PAGE));
    }

    public function contact(Request $request, string $contact, ContactVisibility $visibility): JsonResponse
    {
        self::need($request, 'contacts:read');

        return response()->json(['data' => self::contactJson(self::visibleContact($request, $contact, $visibility))]);
    }

    public function createContact(Request $request, ManageContacts $contacts): JsonResponse
    {
        self::need($request, 'contacts:write');

        return self::idempotent($request, function () use ($request, $contacts): JsonResponse {
            $data = self::check($request->all(), ['first_name' => ['required', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
                'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:30'], 'city' => ['nullable', 'string', 'max:80'],
                'state' => ['nullable', 'string', 'size:2'], 'zip' => ['nullable', 'string', 'max:10']]);
            $c = $contacts->create($data, self::user($request));
            if (isset($data['city']) || isset($data['state']) || isset($data['zip'])) {
                $c = $contacts->update($c, array_intersect_key($data, array_flip(['city', 'state', 'zip'])), self::user($request));
            }
            self::audit($request, 'api.contact.created', ['contact' => $c->ulid]);

            return response()->json(['data' => self::contactJson($c->refresh())], 201);
        });
    }

    public function updateContact(Request $request, string $contact, ContactVisibility $visibility, ManageContacts $contacts): JsonResponse
    {
        self::need($request, 'contacts:write');
        $c = self::visibleContact($request, $contact, $visibility);
        $data = self::check($request->all(), ['first_name' => ['sometimes', 'string', 'max:80'], 'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'], 'state' => ['sometimes', 'nullable', 'string', 'size:2'], 'zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'lifecycle' => ['sometimes', 'in:lead,active,under_contract,past_client,sphere']]);
        $c = $contacts->update($c, $data, self::user($request));
        self::audit($request, 'api.contact.updated', ['contact' => $c->ulid, 'fields' => array_keys($data)]);

        return response()->json(['data' => self::contactJson($c->refresh())]);
    }

    public function note(Request $request, string $contact, ContactVisibility $visibility, Timeline $timeline): JsonResponse
    {
        self::need($request, 'contacts:write');
        $c = self::visibleContact($request, $contact, $visibility);

        return self::idempotent($request, function () use ($request, $c, $timeline): JsonResponse {
            $data = self::check($request->all(), ['body' => ['required', 'string', 'max:10000']]);
            $a = $timeline->log($c, ['type' => 'note', 'body' => $data['body']], self::user($request));

            return response()->json(['data' => ['id' => $a->id, 'type' => 'note', 'body' => $a->body, 'created_at' => $a->getAttribute('created_at')?->toIso8601String()]], 201);
        });
    }

    public function task(Request $request, string $contact, ContactVisibility $visibility, Tasks $tasks): JsonResponse
    {
        self::need($request, 'contacts:write');
        $c = self::visibleContact($request, $contact, $visibility);

        return self::idempotent($request, function () use ($request, $c, $tasks): JsonResponse {
            $data = self::check($request->all(), ['title' => ['required', 'string', 'max:200'], 'due_at' => ['required', 'date'], 'type' => ['nullable', 'in:call,text,email,follow_up,other'],
                'notes' => ['nullable', 'string', 'max:2000']]);
            $t = $tasks->create($c, ['title' => $data['title'], 'due_at' => (string) $data['due_at'], 'type' => $data['type'] ?? 'follow_up', 'notes' => $data['notes'] ?? null],
                self::user($request));

            return response()->json(['data' => self::taskJson($t)], 201);
        });
    }

    public function tasks(Request $request, Tasks $tasks): JsonResponse
    {
        self::need($request, 'tasks:read');
        $v = self::check($request->query(), ['status' => ['nullable', 'in:open,done,all'], 'cursor' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $q = $tasks->visibleTo(self::user($request));
        match ($v['status'] ?? 'open') {
            'open' => $q->whereNull('completed_at'),
            'done' => $q->whereNotNull('completed_at'),
            default => null,
        };

        return self::page($q, $request, fn (Task $t): array => self::taskJson($t), (int) ($v['limit'] ?? self::PAGE));
    }

    public function deals(Request $request): JsonResponse
    {
        self::need($request, 'deals:read');
        $v = self::check($request->query(), ['status' => ['nullable', 'string', 'max:20'], 'cursor' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $user = self::user($request);
        $q = Deal::query();
        if (! DealAccess::reviewer($user)) {
            $q->where('agent_user_id', DealAccess::actingFor($user));
        }
        if (($v['status'] ?? null) !== null) {
            $q->where('status', $v['status']);
        }

        return self::page($q, $request, fn (Deal $d): array => self::dealJson($d), (int) ($v['limit'] ?? self::PAGE));
    }

    public function deal(Request $request, string $deal): JsonResponse
    {
        self::need($request, 'deals:read');
        $d = Deal::query()->where('ulid', $deal)->first();
        $user = self::user($request);
        if ($d === null || ! (DealAccess::reviewer($user) || $d->agent_user_id === DealAccess::actingFor($user))) {
            return ApiAuth::error(404, 'not_found', 'No such deal, or you cannot see it.');
        }

        return response()->json(['data' => self::dealJson($d)]);
    }

    public function webhooks(Request $request): JsonResponse
    {
        self::need($request, 'webhooks:manage', true);

        return response()->json(['data' => Connection::query()->where('app_key', 'webhooks')->where('settings->api_caller', (string) $request->attributes->get('api_caller'))
            ->get()->map(fn (Connection $c): array => ['id' => $c->ulid, 'url' => $c->settings['url'] ?? null, 'events' => $c->settings['events'] ?? [], 'status' => $c->status])->values()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        self::need($request, 'webhooks:manage', true);
        $data = self::check($request->all(), ['url' => ['required', 'string', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string', 'in:'.implode(',', array_keys(Outbox::EVENTS))]]);
        try {
            $target = SafeUrl::check($data['url']);
        } catch (ValidationException $e) {
            return ApiAuth::error(422, 'invalid', 'That address cannot receive webhooks.', $e->errors());
        }
        $secret = 'whsec_'.Str::random(40);
        $c = Connection::query()->create(['app_key' => 'webhooks', 'owner_user_id' => null, 'label' => mb_substr('API: '.$target['host'], 0, 120), 'status' => 'connected',
            'credentials' => ['secret' => $secret], 'settings' => ['url' => $target['url'], 'events' => array_values(array_unique($data['events'])),
                'api_caller' => (string) $request->attributes->get('api_caller')], 'failures' => 0, 'connected_by' => self::user($request)->id]);
        self::audit($request, 'api.webhook.created', ['connection' => $c->ulid, 'host' => $target['host']]);

        return response()->json(['data' => ['id' => $c->ulid, 'url' => $target['url'], 'events' => array_values(array_unique($data['events'])), 'secret' => $secret]], 201);
    }

    public function unsubscribe(Request $request, string $webhook): JsonResponse
    {
        self::need($request, 'webhooks:manage', true);
        $c = Connection::query()->where('ulid', $webhook)->where('app_key', 'webhooks')->first();
        if ($c === null || ($c->settings['api_caller'] ?? null) !== (string) $request->attributes->get('api_caller')) {
            return ApiAuth::error(404, 'not_found', 'No such webhook for this key or app.');
        }
        $c->delete();
        self::audit($request, 'api.webhook.deleted', ['connection' => $webhook]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private static function contactJson(Contact $c): array
    {
        return ['id' => $c->ulid, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->primary_email, 'phone' => $c->primary_phone,
            'city' => $c->city, 'state' => $c->state, 'zip' => $c->zip, 'lifecycle' => $c->lifecycle->value, 'source' => $c->source->value,
            'owner' => $c->owner_user_id !== null ? User::query()->whereKey($c->owner_user_id)->value('ulid') : null,
            'consent' => ['email' => (bool) $c->email_opt_in, 'sms' => (bool) $c->sms_opt_in, 'do_not_call' => (bool) $c->do_not_call],
            'created_at' => $c->getAttribute('created_at')?->toIso8601String(), 'updated_at' => $c->getAttribute('updated_at')?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private static function taskJson(Task $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority, 'due_at' => $t->due_at->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(), 'contact' => $t->contact_id !== null ? Contact::query()->whereKey($t->contact_id)->value('ulid') : null];
    }

    /**
     * Money: the sale price only; never commission, splits, fees or bank details.
     *
     * @return array<string, mixed>
     */
    private static function dealJson(Deal $d): array
    {
        return ['id' => $d->ulid, 'type' => $d->type, 'status' => $d->status, 'street' => $d->street, 'city' => $d->city, 'state' => $d->state_code, 'zip' => $d->postal_code,
            'mls_number' => $d->mls_number, 'price' => $d->price_cents / 100, 'contract_date' => $d->contract_date?->toDateString(),
            'closing_date' => $d->closing_date?->toDateString(), 'closed_on' => $d->closed_on?->toDateString(),
            'agent' => User::query()->whereKey($d->agent_user_id)->value('ulid'), 'contact' => $d->contact_id !== null ? Contact::query()->whereKey($d->contact_id)->value('ulid') : null,
            'updated_at' => $d->getAttribute('updated_at')?->toIso8601String()];
    }

    /**
     * Newest-changed first, then by id; the cursor carries the last row's position.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @param  \Closure(T): array<string, mixed>  $map
     */
    private static function page(Builder $q, Request $request, \Closure $map, int $limit): JsonResponse
    {
        $cursor = (string) $request->query('cursor', '');
        if ($cursor !== '') {
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            [$at, $id] = is_string($raw) && str_contains($raw, '|') ? explode('|', $raw, 2) : ['', '0'];
            $q->where(fn ($w) => $w->where('updated_at', '<', $at)->orWhere(fn ($x) => $x->where('updated_at', $at)->where('id', '<', (int) $id)));
        }
        $rows = $q->orderByDesc('updated_at')->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();
        $next = $more && $last !== null ? rtrim(strtr(base64_encode($last->getAttributeValue('updated_at')?->format('Y-m-d H:i:s').'|'.$last->getKey()), '+/', '-_'), '=') : null;

        return response()->json(['data' => $rows->map($map)->values(), 'next_cursor' => $next]);
    }

    private static function visibleContact(Request $request, string $ulid, ContactVisibility $visibility): Contact
    {
        $c = $visibility->query(self::user($request))->where('ulid', $ulid)->first();
        if (! $c instanceof Contact) {
            abort(ApiAuth::error(404, 'not_found', 'No such contact, or you cannot see it.'));
        }

        return $c;
    }

    /** Runs a write once per Idempotency-Key (24 h): a retry gets the same answer instead of a second contact. */
    private static function idempotent(Request $request, \Closure $write): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $write();
        }
        $cacheKey = 'api:idem:'.$request->attributes->get('api_caller').':'.hash('sha256', $key.$request->path());
        $hit = Cache::get($cacheKey);
        if (is_array($hit)) {
            return response()->json($hit['body'], (int) $hit['status'])->header('Idempotent-Replay', 'true');
        }
        $response = $write();
        if ($response->getStatusCode() < 500) {
            Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addDay());
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function check(array $input, array $rules): array
    {
        $v = Validator::make($input, $rules);
        if ($v->fails()) {
            abort(ApiAuth::error(422, 'invalid', 'Some fields need fixing.', $v->errors()->toArray()));
        }

        return $v->validated();
    }

    private static function need(Request $request, string $scope, bool $leaders = false): void
    {
        if (! in_array($scope, (array) $request->attributes->get('api_scopes', []), true)) {
            abort(ApiAuth::error(403, 'insufficient_scope', "This key or app was not given \"{$scope}\"."));
        }
        if ($leaders && ! in_array(self::user($request)->kind, [UserKind::Owner, UserKind::Staff], true)) {
            abort(ApiAuth::error(403, 'forbidden', 'Only owners and staff can manage webhooks.'));
        }
    }

    /** @param array<string, mixed> $meta */
    private static function audit(Request $request, string $action, array $meta): void
    {
        app(AuditLogger::class)->record($action, metadata: $meta + ['via' => (string) $request->attributes->get('api_caller')]);
    }

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
