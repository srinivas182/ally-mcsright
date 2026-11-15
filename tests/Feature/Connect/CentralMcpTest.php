<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\Mcp\AiAssistants;
use Modules\Core\Support\Connections;

/* Sprint M2b: one AI assistant address for every brokerage — find the brokerage by email, sign in there, and every
   token and call is routed to that brokerage only. */

const CENTRAL = 'https://mcp.rightally.app';
const CLAUDE_CB = 'https://claude.ai/api/mcp/auth_callback';

function centralKeys(): void
{
    static $keys = null;
    if ($keys === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $keys = [$private, openssl_pkey_get_details($key)['key']];
    }
    config(['passport.private_key' => $keys[0], 'passport.public_key' => $keys[1]]);
}

function centralVerifier(): string
{
    return str_repeat('v', 64);
}

/** The whole journey from the AI app to a token. Returns the token response. */
function centralConnect(User $as, string $email): TestResponse
{
    $client = test()->postJson(CENTRAL.'/register', ['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CB]])->assertCreated()->json('client_id');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', centralVerifier(), true)), '+/', '-_'), '=');
    $page = test()->get(CENTRAL.'/authorize?'.http_build_query(['client_id' => $client, 'redirect_uri' => CLAUDE_CB, 'response_type' => 'code', 'scope' => 'mcp:use',
        'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))->assertOk()->assertSee('Connect Claude to RightAlly');
    preg_match('/name="pending" value="([^"]+)"/', (string) $page->getContent(), $m);

    $toBrokerage = (string) test()->post(CENTRAL.'/authorize/find', ['pending' => $m[1], 'email' => $email])->assertRedirect()->headers->get('Location');
    expect($toBrokerage)->toStartWith('https://acme.rightally.app/oauth/authorize?');

    $consent = test()->actingAs($as)->get($toBrokerage)->assertInertia(fn (Assert $p) => $p->component('mcp/consent')->where('client', 'Claude')->where('returns_to', 'claude.ai'));
    $authToken = $consent->viewData('page')['props']['auth_token'];
    $back = (string) test()->actingAs($as)->post('https://acme.rightally.app/oauth/authorize', ['auth_token' => $authToken])->headers->get('Location');
    expect($back)->toStartWith(CENTRAL.'/oauth/return?');

    $toClaude = (string) test()->get($back)->headers->get('Location');
    expect($toClaude)->toStartWith(CLAUDE_CB.'?');
    parse_str((string) parse_url($toClaude, PHP_URL_QUERY), $q);
    expect($q['state'])->toBe('xyz')->and($q['code'])->toStartWith(test()->tenant->ulid.'~');
    auth()->forgetGuards();

    return test()->postJson(CENTRAL.'/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => CLAUDE_CB,
        'code_verifier' => centralVerifier()]);
}

function centralMcp(string $token, string $method = 'tools/list', array $params = []): TestResponse
{
    auth()->forgetGuards();

    return test()->postJson(CENTRAL.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
        ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer '.$token]);
}

beforeEach(function (): void {
    centralKeys();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'email' => 'ann@acme.test']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Maria', 'last_name' => 'Lopez', 'email' => 'maria@example.test', 'source' => 'open_house']);
    $this->actingAs($this->bob)->post('/contacts', ['first_name' => 'Mark', 'last_name' => 'Other', 'email' => 'mark@example.test', 'source' => 'open_house']);
    auth()->forgetGuards();
});

it('publishes one address for every brokerage, and registers only known AI apps', function (): void {
    $this->getJson(CENTRAL.'/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', CENTRAL.'/mcp')->assertJsonPath('authorization_servers', [CENTRAL]);
    $this->getJson(CENTRAL.'/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('token_endpoint', CENTRAL.'/token')
        ->assertJsonPath('authorization_endpoint', CENTRAL.'/authorize');
    $this->postJson(CENTRAL.'/register', ['client_name' => 'X', 'redirect_uris' => ['https://evil.example.com/cb']])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    $this->postJson(CENTRAL.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])->assertUnauthorized();
});

it('finds the brokerage by email, signs in there, and acts as that person only', function (): void {
    $token = centralConnect($this->ann, 'ANN@acme.test')->assertOk()->json();
    expect($token['access_token'])->toStartWith($this->tenant->ulid.'~')->and($token['refresh_token'])->toStartWith($this->tenant->ulid.'~');

    $tools = collect(centralMcp($token['access_token'])->assertOk()->json('result.tools'))->pluck('name')->all();
    expect($tools)->toContain('search_contacts', 'daily_brief');
    $found = centralMcp($token['access_token'], 'tools/call', ['name' => 'search_contacts', 'arguments' => ['query' => 'ma']])->assertOk()->json('result.content.0.text');
    expect(collect(json_decode((string) $found, true)['data'])->pluck('name')->all())->toBe(['Maria Lopez']); // never Bob's contacts

    // The AI app shows up in Ann's connected assistants, as Claude.
    expect(collect(app(AiAssistants::class)->connected($this->ann))->pluck('app')->all())->toBe(['ai_claude']);

    // Refreshing goes to the same brokerage.
    auth()->forgetGuards();
    $fresh = $this->postJson(CENTRAL.'/token', ['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'],
        'client_id' => DB::connection(Connections::control())->table('mcp_central_clients')->value('id')])->assertOk()->json('access_token');
    centralMcp($fresh)->assertOk();
});

it('refuses tokens without their brokerage, or with someone else\'s', function (): void {
    $token = centralConnect($this->ann, 'ann@acme.test')->json('access_token');
    $raw = substr($token, 27);
    centralMcp($raw)->assertUnauthorized(); // a brokerage token on its own is not accepted here

    $other = $this->onTenantHost('other');
    centralMcp($other->ulid.'~'.$raw)->assertUnauthorized();
});

it('stops working when the person disconnects it in RightAlly', function (): void {
    $token = centralConnect($this->ann, 'ann@acme.test')->json('access_token');
    $row = collect(app(AiAssistants::class)->connected($this->ann))->sole();
    app(AiAssistants::class)->disconnect($this->ann, $this->ann->ulid, $row['client_id']);
    centralMcp($token)->assertUnauthorized();
});

it('says plainly when no account has that email, and keeps only hashed emails', function (): void {
    $client = $this->postJson(CENTRAL.'/register', ['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CB]])->json('client_id');
    $page = $this->get(CENTRAL.'/authorize?'.http_build_query(['client_id' => $client, 'redirect_uri' => CLAUDE_CB, 'response_type' => 'code', 'state' => 's',
        'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']));
    preg_match('/name="pending" value="([^"]+)"/', (string) $page->getContent(), $m);
    $this->post(CENTRAL.'/authorize/find', ['pending' => $m[1], 'email' => 'nobody@nowhere.test'])->assertStatus(422)->assertSee('We couldn&#039;t find a RightAlly account', false);

    expect(DB::connection(Connections::control())->table('identity_directory')->pluck('email_hash')->implode(','))->not->toContain('@');
    $this->get(CENTRAL.'/authorize?client_id=nope&redirect_uri='.urlencode(CLAUDE_CB))->assertOk()->assertSee('not registered with RightAlly');
});
