<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;

/*
| SX1 (agent privacy): one agent can never open or change another agent's personal contacts, deals or documents.
| Every signed-in back-office route that takes one of Ann's records is tried as Bob, another agent in the same
| brokerage. Reading must answer 403 or 404; an action must be refused (403, 404) or rejected before it does
| anything, and Ann's records must be unchanged afterwards.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    $this->contact = app(ManageContacts::class)->create(['first_name' => 'Jordan', 'last_name' => 'Private', 'email' => 'jordan@x.test', 'sphere' => 'personal'], $this->ann);
    // A caller name from the phone check, so "use the caller's name" would really change something.
    Contact::query()->whereKey($this->contact->id)->update(['caller_name' => 'Somebody Else']);
    $this->task = app(Tasks::class)->create($this->contact, ['title' => 'Call Jordan', 'due' => 'today'], $this->ann);
    $this->actingAs($this->ann)->post('/deals', ['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'tx', 'price' => '400000', 'contact' => $this->contact->ulid]);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->item = Item::query()->where('deal_id', $this->deal->id)->firstOrFail();
    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'item' => $this->item->ulid]);
    $this->document = Document::query()->where('deal_id', $this->deal->id)->firstOrFail();
});

/** Ann's records by route parameter name. */
function apsParams(object $t): array
{
    return ['contact' => $t->contact->ulid, 'deal' => $t->deal->ulid, 'document' => $t->document->ulid, 'item' => $t->item->ulid, 'task' => $t->task->ulid];
}

/** A fingerprint of Ann's records, to prove nothing Bob tried changed them. */
function apsSnapshot(object $t): string
{
    $cell = DB::connection(Connections::cell());

    return md5((string) json_encode([
        Contact::query()->withTrashed()->whereKey($t->contact->id)->first()?->getAttributes(),
        Task::query()->whereKey($t->task->id)->first()?->getAttributes(),
        Deal::query()->whereKey($t->deal->id)->first()?->getAttributes(),
        Document::query()->whereKey($t->document->id)->first()?->getAttributes(),
        $cell->table('tx_items')->where('id', $t->item->id)->first(),
        $cell->table('crm_activities')->where('contact_id', $t->contact->id)->count(),
        $cell->table('tx_parties')->where('deal_id', $t->deal->id)->count(),
    ]));
}

/**
 * Signed-in back-office routes whose every parameter is one of Ann's records.
 *
 * @return list<array{0: string, 1: string}>
 */
function apsRoutes(array $params): array
{
    $out = [];
    foreach (Router::getRoutes()->getRoutes() as $r) {
        /** @var Route $r */
        $mw = implode(' ', array_map(fn ($m): string => is_string($m) ? $m : $m::class, app('router')->gatherRouteMiddleware($r)));
        $names = $r->parameterNames();
        if ($names === [] || ! str_contains($mw, 'EnsureTenantWorkspace') || ! str_contains($mw, 'Authenticate') || array_diff($names, array_keys($params)) !== []) {
            continue;
        }
        $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]], $r->uri());
        foreach (array_diff($r->methods(), ['HEAD']) as $method) {
            $out[] = [$method, $uri];
        }
    }

    return $out;
}

it('never lets one agent open another agent\'s contacts, deals, documents or tasks', function (): void {
    $routes = apsRoutes(apsParams($this));
    $open = [];
    foreach (array_filter($routes, fn (array $r): bool => $r[0] === 'GET') as [, $uri]) {
        Cache::flush();
        $status = $this->actingAs($this->bob)->get($uri)->baseResponse->getStatusCode();
        if (! in_array($status, [403, 404], true)) {
            $open[] = "GET {$uri} answered {$status}";
        }
    }

    expect(count($routes))->toBeGreaterThan(60)->and($open)->toBe([]);
});

it('never lets one agent change another agent\'s contacts, deals, documents or tasks', function (): void {
    $before = apsSnapshot($this);
    $accepted = [];
    foreach (array_filter(apsRoutes(apsParams($this)), fn (array $r): bool => $r[0] !== 'GET') as [$method, $uri]) {
        Cache::flush(); // rate limits must not stop the attempt
        $response = $this->actingAs($this->bob)->withSession(['auth.password_confirmed_at' => time()])->call($method, $uri, ['_token' => csrf_token()]);
        $status = $response->baseResponse->getStatusCode();
        if (! in_array($status, [403, 404, 405, 422], true) && ! ($status === 302 && session()->has('errors'))) {
            $accepted[] = "{$method} {$uri} answered {$status}";
        }
        session()->forget('errors');
    }

    expect(apsSnapshot($this))->toBe($before)->and($accepted)->toBe([]);
});

it('keeps an agent\'s personal contacts from the owner unless the brokerage allows it', function (): void {
    $this->actingAs($this->owner)->get('/contacts/'.$this->contact->ulid)->assertNotFound();
    $this->actingAs($this->ann)->get('/contacts/'.$this->contact->ulid)->assertOk();
});
