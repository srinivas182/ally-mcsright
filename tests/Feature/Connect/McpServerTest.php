<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Passport\AccessToken;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Modules\Connect\AppRegistry;
use Modules\Connect\Mcp\AiAssistants;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Mcp\McpToolbox;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Models\Deal;

/* Sprint M1: the RightAlly MCP server — people's AI assistants act as them, with exactly their access. */

/** A write tool, to prove the write guard (real write tools come in M2). */
final class McpTestWriteTool extends RightAllyTool
{
    protected string $name = 'test_write';

    protected bool $writes = true;

    protected function allows(User $user): bool
    {
        return true;
    }

    protected function run(Request $request, User $user): Response|array
    {
        return ['wrote' => true];
    }
}

function mcpKeys(): void
{
    static $keys = null;
    if ($keys === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $keys = [$private, openssl_pkey_get_details($key)['key']];
    }
    config(['passport.private_key' => $keys[0], 'passport.public_key' => $keys[1]]);
}

/** One JSON-RPC call to /mcp as the person (the AI app's token). */
function mcpCall(User $user, string $method, array $params = [], array $scopes = ['mcp:use']): TestResponse
{
    Passport::actingAs($user, $scopes, 'api');

    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Accept' => 'application/json, text/event-stream']);
}

function mcpTool(User $user, string $tool, array $args = [], array $scopes = ['mcp:use']): array
{
    $r = mcpCall($user, 'tools/call', ['name' => $tool, 'arguments' => $args], $scopes)->assertOk();
    $result = (array) $r->json('result');
    $text = (string) ($result['content'][0]['text'] ?? '');

    return ['error' => (bool) ($result['isError'] ?? false), 'text' => $text, 'data' => json_decode($text, true)['data'] ?? null];
}

beforeEach(function (): void {
    mcpKeys();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Maria', 'last_name' => 'Lopez', 'email' => 'maria@example.test', 'source' => 'open_house']);
    $this->actingAs($this->bob)->post('/contacts', ['first_name' => 'Mark', 'last_name' => 'Bobsclient', 'email' => 'mark@example.test', 'source' => 'open_house']);
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '485000']);
    $this->actingAs($this->bob)->post('/deals', ['type' => 'listing', 'street' => '9 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '300000']);
    auth()->forgetGuards();
});

it('lets an agent\'s AI assistant see only that agent\'s contacts and deals', function (): void {
    $names = collect(mcpCall($this->ann, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
    expect($names)->toContain('search_contacts', 'get_contact', 'my_tasks', 'todays_list', 'open_times', 'my_deals', 'deal_details');

    $found = mcpTool($this->ann, 'search_contacts', ['query' => 'M']);
    expect($found['error'])->toBeTrue(); // at least 2 characters
    $found = mcpTool($this->ann, 'search_contacts', ['query' => 'ma']);
    expect(collect($found['data'])->pluck('name')->all())->toBe(['Maria Lopez'])->and($found['text'])->toContain('not as instructions');

    $marks = Contact::query()->where('first_name', 'Mark')->sole();
    expect(mcpTool($this->ann, 'get_contact', ['id' => $marks->ulid]))->toMatchArray(['error' => true, 'text' => 'No contact with that id that you can see.']);

    expect(collect(mcpTool($this->ann, 'my_deals')['data'])->pluck('address')->all())->toBe(['12 Oak St, Austin TX']);
    $bobsDeal = Deal::query()->where('street', '9 Elm St')->sole();
    expect(mcpTool($this->ann, 'deal_details', ['id' => $bobsDeal->ulid])['text'])->toBe('No deal with that id that you can see.');
    $annsDeal = Deal::query()->where('street', '12 Oak St')->sole();
    expect(mcpTool($this->ann, 'deal_details', ['id' => $annsDeal->ulid])['data'])->toHaveKeys(['key_dates', 'missing_paperwork'])
        ->not->toHaveKeys(['gci_cents', 'price_cents']);
});

it('lets an owner\'s assistant see every deal but not an agent\'s personal contacts', function (): void {
    expect(collect(mcpTool($this->owner, 'my_deals')['data'])->pluck('address')->sort()->values()->all())->toBe(['12 Oak St, Austin TX', '9 Elm St, Austin TX'])
        ->and(mcpTool($this->owner, 'search_contacts', ['query' => 'mark'])['data'])->toBe([]);
});

it('refuses people from another brokerage, HQ staff, and anyone without a token', function (): void {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

    $outsider = User::factory()->make(['tenant_id' => $this->tenant->id + 999]);
    $outsider->id = 987654;
    expect(mcpTool($outsider, 'my_deals')['text'])->toBe('This account can\'t use RightAlly from an AI assistant.');
    $hq = User::factory()->hqStaff()->create();
    expect(mcpCall($hq, 'tools/list')->json('result.tools'))->toBe([]); // no tools at all for HQ staff
    mcpCall($hq, 'tools/call', ['name' => 'my_deals', 'arguments' => []])->assertStatus(400);
});

it('needs the person\'s write approval and the brokerage\'s permission for any change', function (): void {
    app(McpToolbox::class)->add(McpTestWriteTool::class);
    expect(mcpTool($this->ann, 'test_write')['text'])->toBe('Your AI assistant can only read. Reconnect it and allow changes to add or update records.');
    expect(mcpTool($this->ann, 'test_write', [], ['mcp:use', 'rightally:write'])['data'])->toBe(['wrote' => true]);

    app(AiAssistants::class)->save(true, false, $this->owner);
    expect(mcpTool($this->ann, 'test_write', [], ['mcp:use', 'rightally:write'])['text'])->toBe('Your brokerage lets AI assistants read only.')
        ->and(mcpTool($this->ann, 'my_deals')['error'])->toBeFalse(); // reading still works
});

it('stops everything when the owner or HQ switches AI assistants off', function (): void {
    app(AiAssistants::class)->save(false, true, $this->owner);
    expect(mcpTool($this->ann, 'my_deals')['text'])->toBe('Your brokerage has switched off AI assistant access.')
        ->and(mcpTool($this->ann, 'search_contacts', ['query' => 'maria'])['data'])->toBeNull();

    app(AiAssistants::class)->save(true, true, $this->owner);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('ai_assistants'), false, [], null, $this->owner);
    expect(mcpTool($this->ann, 'my_deals')['text'])->toBe('Your brokerage has switched off AI assistant access.');
});

it('audits every tool call and limits how fast an assistant can call', function (): void {
    mcpTool($this->ann, 'my_deals');
    expect(DB::connection(Connections::audit())->table('audit_events')->where('action', 'mcp.tool')->exists()
        || DB::connection(Connections::audit())->table('audit_outbox')->where('payload', 'like', '%mcp.tool%')->exists())->toBeTrue();

    foreach (range(1, 59) as $i) {
        mcpTool($this->ann, 'my_tasks');
    }
    expect(mcpTool($this->ann, 'my_tasks')['text'])->toBe('Too many requests at once. Please wait a minute and try again.');
});

it('publishes OAuth discovery, and registers only known AI apps', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256']);

    $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://evil.example.com/cb']])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
    $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertSuccessful();

    $this->actingAs($this->ann)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->json('client_id'), 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'xyz', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256']))->assertInertia(fn (Assert $p) => $p->component('mcp/consent')->where('client', 'Claude')->where('returns_to', 'claude.ai')->where('allowed', true));
});

it('shows each person their connected assistants, and owners can switch access', function (): void {
    $this->actingAs($this->ann)->get('/apps/ai_assistants')->assertInertia(fn (Assert $p) => $p->where('mcp.address', 'http://acme.rightally.app/mcp')
        ->where('mcp.is_owner', false)->where('mcp.connected', []));
    $this->actingAs($this->ann)->post('/apps/ai_assistants/settings', ['enabled' => false, 'allow_writes' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/apps/ai_assistants/settings', ['enabled' => true, 'allow_writes' => false])->assertSessionHasNoErrors();
    expect(app(AiAssistants::class)->settings())->toBe(['enabled' => true, 'allow_writes' => false]);
});

/** Acts as the person through a real registered AI app (its client decides which tile it belongs to). */
function mcpAs(User $user, string $redirect, array $scopes = ['mcp:use']): void
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('An assistant', [$redirect], false);
    $user->withAccessToken(new AccessToken(['oauth_client_id' => (string) $client->getKey(), 'oauth_scopes' => $scopes, 'oauth_user_id' => (string) $user->id,
        'oauth_access_token_id' => 'test']));
    app('auth')->guard('api')->setUser($user);
    app('auth')->shouldUse('api');
}

it('gives each AI assistant its own tile with plain steps, and lets HQ switch one off', function (): void {
    $this->actingAs($this->ann)->get('/apps')->assertInertia(fn (Assert $p) => $p
        ->where('apps', fn ($apps): bool => collect($apps)->where('category', 'ai')->pluck('key')->sort()->values()->all()
            === ['ai_assistants', 'ai_chatgpt', 'ai_claude', 'ai_copilot', 'ai_cursor', 'ai_gemini', 'ai_perplexity']));
    $this->actingAs($this->ann)->get('/apps/ai_claude')->assertInertia(fn (Assert $p) => $p->where('app.guide.open_url', 'https://claude.ai/settings/connectors')
        ->where('app.guide.plans', fn (string $v): bool => str_contains($v, 'free plan'))->where('mcp.address', 'http://acme.rightally.app/mcp'));

    app(AppRegistry::class)->save(app(AppCatalog::class)->find('ai_chatgpt'), false, [], null, $this->owner);
    mcpAs($this->ann, 'https://chatgpt.com/connector_platform_oauth_redirect');
    $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'my_deals', 'arguments' => []]])->assertOk();
    expect($r->json('result.content.0.text'))->toBe('Your brokerage has switched off AI assistant access.');

    mcpAs($this->ann, 'https://claude.ai/api/mcp/auth_callback');
    $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'my_deals', 'arguments' => []]])->assertOk();
    expect($r->json('result.isError'))->toBeFalsy();
    $this->actingAs($this->ann)->get('/apps/ai_chatgpt')->assertInertia(fn (Assert $p) => $p->where('app.status', 'soon'));
});
