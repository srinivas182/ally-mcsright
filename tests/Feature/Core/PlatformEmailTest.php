<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
    $keys = ['smtp_login' => 'abc@smtp-brevo.com', 'smtp_key' => 'xkeysib-123', 'from_address' => 'no-reply@rightally.app', 'from_name' => 'RightAlly'];
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
        ->and($this->sent[0]['smtp']['host'])->toBe('smtp-relay.brevo.com')
        ->and($this->sent[0]['smtp']['username'])->toBe('abc@smtp-brevo.com');
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
        ->and(PlatformEmail::check('brevo', ['smtp_login' => '', 'smtp_key' => '']))->toBe('Fill in every field.');
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
