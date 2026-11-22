<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\PlatformEmail;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/*
| EM1: RightAlly's own email goes out through the provider HQ switches on in Outside services (Brevo recommended,
| Postmark, Amazon SES). Off uses the server's mailer; Test sends everything only to HQ's test address; Live sends to
| the real people, from the verified address.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'mail.from' => ['address' => 'hello@rightally.app', 'name' => 'RightAlly'],
        'mail.mailers.platform' => ['transport' => 'platform', 'fallback' => 'array'], 'mail.default' => 'platform']);
    app('mail.manager')->forgetMailers();
    PlatformEmail::forget();
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->sent = [];
    PlatformEmail::$sender = function (array $smtp, Message $m, Envelope $e): void {
        $this->sent[] = ['smtp' => $smtp, 'message' => $m, 'to' => array_map(fn ($a) => $a->getAddress(), $e->getRecipients()), 'from' => $e->getSender()->getAddress()];
    };
});

afterEach(function (): void {
    PlatformEmail::$sender = null;
    PlatformEmail::forget();
});

function emSwitch(string $mode): void
{
    $keys = ['api_key' => 'xkeysib-123', 'from_address' => 'no-reply@rightally.app', 'from_name' => 'RightAlly'];
    $services = app(OutsideServices::class);
    $services->save(0, PlatformEmail::SERVICE, 'brevo', $keys, $keys, 'tester@mcs.test', test()->staff);
    // "Check connection" signs in to the provider; here we record that it passed, which unlocks Live.
    DB::connection(Connections::control())->table('outside_services')->where('service', PlatformEmail::SERVICE)->update(['test_passed_at' => now()]);
    $services->setMode(0, PlatformEmail::SERVICE, $mode, test()->staff);
    PlatformEmail::forget();
}

it('uses the server mailer while the switch is off', function (): void {
    Mail::raw('Hello', fn ($m) => $m->to('dana@oak.test')->subject('Welcome'));
    expect($this->sent)->toBe([])
        ->and(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('sends everything only to the test address in Test mode, and says who it was for', function (): void {
    emSwitch('test');
    Mail::raw('Hello', fn ($m) => $m->to('dana@oak.test')->subject('Welcome'));
    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['to'])->toBe(['tester@mcs.test'])
        ->and($this->sent[0]['smtp'])->toBe(['api' => 'brevo']); // EM2: Brevo goes through its API
    $email = $this->sent[0]['message'];
    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->getSubject())->toBe('[TEST to dana@oak.test] Welcome')
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@rightally.app')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('hello@rightally.app');
});

it('sends to the real people from the verified address in Live mode', function (): void {
    emSwitch('live');
    Mail::raw('Your code is 123456', fn ($m) => $m->to('dana@oak.test')->subject('Sign-in code'));
    expect($this->sent[0]['to'])->toBe(['dana@oak.test'])->and($this->sent[0]['from'])->toBe('no-reply@rightally.app')
        ->and($this->sent[0]['message']->getSubject())->toBe('Sign-in code');
});

it('knows each provider\'s mail server, and refuses incomplete sign-ins', function (): void {
    expect(PlatformEmail::smtp('postmark_send', ['server_token' => 'tok'])['host'] ?? null)->toBe('smtp.postmarkapp.com')
        ->and(PlatformEmail::smtp('ses', ['region' => 'us-east-1', 'smtp_username' => 'u', 'smtp_password' => 'p'])['host'] ?? null)->toBe('email-smtp.us-east-1.amazonaws.com')
        ->and(PlatformEmail::smtp('ses', ['region' => 'not a region']))->toBeNull()
        ->and(PlatformEmail::check('postmark_send', ['server_token' => '']))->toBe('Fill in every field.')
        ->and(PlatformEmail::smtp('brevo', ['smtp_login' => 'abc@smtp-brevo.com', 'smtp_key' => 'k'])['host'] ?? null)->toBe('smtp-relay.brevo.com'); // keys saved before EM2
});

it('is listed for HQ in Outside services', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/services')->assertOk()->assertSee('Platform email');
});

it('gives the HQ invitation link on screen while email is not set up yet', function (): void {
    config(['mail.default' => 'array']);
    $this->artisan('tenants:bootstrap-hq', ['--admin-email' => 'srini@mcs.test'])
        ->expectsOutputToContain('Email is not set up yet')
        ->expectsOutputToContain('/invitations/')
        ->assertSuccessful();
});

it('sends through Brevo\'s API with an API key, from the verified sender (EM2)', function (): void {
    PlatformEmail::$sender = null;
    Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<1@brevo>'], 201)]);
    $keys = ['api_key' => 'xkeysib-abc', 'from_address' => 'no-reply@rightally.app', 'from_name' => 'RightAlly'];
    $services = app(OutsideServices::class);
    $services->save(0, PlatformEmail::SERVICE, 'brevo', $keys, $keys, 'tester@mcs.test', $this->staff);
    DB::connection(Connections::control())->table('outside_services')->where('service', PlatformEmail::SERVICE)->update(['test_passed_at' => now()]);
    $services->setMode(0, PlatformEmail::SERVICE, 'live', $this->staff);
    PlatformEmail::forget();

    Mail::html('<p>Your code is 123456</p>', fn ($m) => $m->to('dana@oak.test')->subject('Sign-in code'));

    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.brevo.com/v3/smtp/email' && $r->hasHeader('api-key', 'xkeysib-abc')
        && $r['sender'] === ['email' => 'no-reply@rightally.app', 'name' => 'RightAlly'] && $r['to'] === [['email' => 'dana@oak.test']]
        && $r['subject'] === 'Sign-in code' && $r['htmlContent'] === '<p>Your code is 123456</p>' && $r['replyTo']['email'] === 'hello@rightally.app');
});

it('checks the Brevo API key and that "Send from" is a verified sender (EM2)', function (): void {
    $keys = ['api_key' => 'xkeysib-abc', 'from_address' => 'no-reply@rightally.app'];
    Http::fake([
        'api.brevo.com/v3/account' => Http::response(['email' => 'hq@rightally.app']),
        'api.brevo.com/v3/senders/domains' => Http::response(['domains' => []]),
        'api.brevo.com/v3/senders' => Http::response(['senders' => [['email' => 'other@rightally.app', 'active' => true]]]),
    ]);
    expect(PlatformEmail::check('brevo', $keys))->toContain('is not a verified sender in Brevo');
});

it('accepts a sender on a domain verified in Brevo (EM2)', function (): void {
    $keys = ['api_key' => 'xkeysib-abc', 'from_address' => 'no-reply@rightally.app'];
    Http::fake([
        'api.brevo.com/v3/account' => Http::response(['email' => 'hq@rightally.app']),
        'api.brevo.com/v3/senders/domains' => Http::response(['domains' => [['domain_name' => 'rightally.app', 'authenticated' => true]]]),
        'api.brevo.com/v3/senders' => Http::response(['senders' => []]),
    ]);
    expect(PlatformEmail::check('brevo', $keys))->toBeNull()
        ->and(PlatformEmail::check('brevo', ['api_key' => 'xkeysib-abc', 'from_address' => '']))->toBe('Fill in the API key and the "Send from" address.');
});

it('says when Brevo refuses the API key (EM2)', function (): void {
    Http::fake(['api.brevo.com/v3/account' => Http::response(['message' => 'Key not found'], 401)]);
    expect(PlatformEmail::check('brevo', ['api_key' => 'xkeysib-bad', 'from_address' => 'no-reply@rightally.app']))->toContain('did not accept the API key');
});
