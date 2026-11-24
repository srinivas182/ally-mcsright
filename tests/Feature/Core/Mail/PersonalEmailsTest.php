<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Modules\Forms\SignatureRequested;
use Modules\Listings\Idx\Mail\IdxAgreementMail;

/*
| EM4: emails greet people by their full name in a plain line, a brokerage's own agreements carry no wire-fraud
| warning, the signed IDX agreement can be queued, each throttled action counts its own tries, and an error in the app
| returns the person to their page with a message.
*/

beforeEach(function (): void {
    config(['mail.default' => 'array']);
    $this->onTenantHost('acme');
});

function em4Html(): string
{
    return (string) collect(app('mailer')->getSymfonyTransport()->messages())->last()?->getOriginalMessage()->getHtmlBody();
}

it('greets an address sent with a name by that name, in a plain line', function (): void {
    Notification::route('mail', 'cory@example.test')->route('name', 'Cory Kammerdiener')
        ->notify(new SignatureRequested('IDX agreement: HAR', 'MCS Realty', 'RightAlly', 'https://x.test/sign/abc', false, false));

    $html = em4Html();
    expect($html)->toContain('Hello Cory Kammerdiener,')->not->toContain('<h1>Hello')
        ->and($html)->toContain('is ready for your signature')
        ->and($html)->not->toContain('Wire fraud');
});

it('keeps the wire-fraud warning on home sale paperwork', function (): void {
    Notification::route('mail', 'buyer@example.test')->route('name', 'Pat Buyer')
        ->notify(new SignatureRequested('Purchase agreement', 'MCS Realty', 'Dana Brooks', 'https://x.test/sign/abc', false));

    expect(em4Html())->toContain('Hello Pat Buyer,')->toContain('Wire fraud warning');
});

it('greets a person on file by their full name', function (): void {
    $user = User::factory()->owner()->create(['first_name' => 'Srini', 'last_name' => 'C']);
    $user->notify(new SignatureRequested('IDX agreement', 'MCS Realty', 'RightAlly', 'https://x.test/sign/abc', false, false));

    expect(em4Html())->toContain('Hello Srini C,');
});

it('queues the signed agreement by its file path, so it can always be sent', function (): void {
    $mail = new IdxAgreementMail('HAR', ['Brokerage' => 'MCS Realty'], 'idx/agreements/signed.pdf', 'IDX agreement.pdf');

    expect(json_encode(serialize($mail)))->not->toBeFalse()
        ->and($mail->path)->toBe('idx/agreements/signed.pdf');
});

it('counts tries per action, not across every throttled page', function (): void {
    $user = User::factory()->owner()->create();
    Route::middleware(['web', 'throttle:2,10'])->post('/em4-a', fn () => 'a')->name('em4.a');
    Route::middleware(['web', 'throttle:2,10'])->post('/em4-b', fn () => 'b')->name('em4.b');
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($user)->post('/em4-a')->assertOk();
    $this->actingAs($user)->post('/em4-a')->assertOk();
    $this->actingAs($user)->post('/em4-b')->assertOk(); // its own count
    $this->actingAs($user)->post('/em4-a')->assertStatus(429);
});

it('sends someone back to their page with a message when an app action fails', function (): void {
    $user = User::factory()->owner()->create();
    Route::middleware(['web'])->post('/em4-fail', fn () => throw new ThrottleRequestsException('Too many'))->name('em4.fail');
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($user)->from('/settings/mls')->withHeaders(['X-Inertia' => 'true'])->post('/em4-fail')
        ->assertRedirect('/settings/mls');
});
