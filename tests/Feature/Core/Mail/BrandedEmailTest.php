<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Identity\Notifications\InvitationNotification;
use Modules\Core\Mail\MailBrand;

/** EM3: emails are branded and written to the person. */
final class Em3PlainNotice extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your report is ready')->line('Your report is ready.')->action('Open it', 'https://example.test/r');
    }
}

function em3Sent(): string
{
    $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last();
    expect($sent)->not->toBeNull();

    return (string) $sent->getOriginalMessage()->getHtmlBody();
}

beforeEach(function (): void {
    config(['mail.default' => 'array']);
});

it('greets the person by first name and sends in the brokerage\'s name', function (): void {
    $tenant = $this->onTenantHost('acme');
    $user = User::factory()->create(['first_name' => 'Maria']);
    $user->notify(new Em3PlainNotice);

    $html = em3Sent();
    expect($html)->toContain('Hello Maria')
        // Compare as text: a generated brokerage name can hold an apostrophe the mail template encodes its own way.
        ->and(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))->toContain('The '.$tenant->name.' team')
        ->and($html)->toContain('Sent with RightAlly on behalf of')
        ->and($html)->not->toContain('Hello!')
        ->and($html)->not->toContain('Regards,');
});

it('uses the RightAlly logo outside a brokerage', function (): void {
    expect(MailBrand::current())->toMatchArray(['name' => 'RightAlly', 'platform' => true])
        ->and(MailBrand::current()['logo'])->toEndWith('/brand/rightally-email.png');
});

it('welcomes a new brokerage owner with first steps', function (): void {
    $mail = (new InvitationNotification('https://x.test/invitations/t', 'MCS Realty', null, 'Dec 1, 2026', 'Srinivas', 'owner', 'mcs-realty-office.rightally.app'))
        ->toMail(new stdClass);

    expect($mail->subject)->toBe('Welcome to the RightAlly family, Srinivas')
        ->and($mail->greeting)->toBe('Hello Srinivas,')
        ->and($mail->actionText)->toBe('Set my password')
        ->and(implode(' ', $mail->outroLines))->toContain('Add your licence and your MLS board')
        ->and($mail->salutation)->toContain('Sunil')->toContain('CEO, RightAlly')
        ->and((new InvitationNotification('https://x.test/invitations/t', 'MCS Realty', null, 'Dec 1, 2026', 'Srinivas', 'owner'))->fromRightAlly)->toBeTrue();
});

it('tells an invited agent who invited them and to which brokerage', function (): void {
    $mail = (new InvitationNotification('https://x.test/invitations/t', 'Lone Star', 'Dana Brooks', 'Dec 1, 2026', 'Maria', 'agent'))
        ->toMail(new stdClass);

    expect($mail->subject)->toBe('Dana Brooks invited you to join Lone Star')
        ->and($mail->greeting)->toBe('Hello Maria,')
        ->and(implode(' ', $mail->introLines))->toContain('**Dana Brooks** has invited you to join **Lone Star** as an agent.');
});
