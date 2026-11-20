<?php

declare(strict_types=1);

namespace Modules\Core\Mail;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Modules\Core\Services\OutsideServices;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * EM1: RightAlly's own email (invitations, sign-in codes, notices, Watchtower) goes out through the provider HQ
 * switches on in HQ › Outside services › Platform email: Brevo (recommended), Postmark or Amazon SES, each Off, Test
 * or Live. No server settings to change: when the switch is Off, mail goes the way the server's MAIL_MAILER says.
 *
 * - Test sends every email only to HQ's first test contact (the real recipients are shown in the subject), so nothing
 *   reaches brokers or agents while HQ tries it.
 * - Live sends to the real recipients, from the address HQ verified with the provider.
 *
 * Read at most once a minute per worker, so long-running workers pick up a change within a minute.
 */
final class PlatformEmail extends AbstractTransport
{
    public const SERVICE = 'platform_email';

    /** Where each provider takes mail (all over SMTP with STARTTLS on port 587). */
    public const HOSTS = ['brevo' => 'smtp-relay.brevo.com', 'postmark_send' => 'smtp.postmarkapp.com'];

    /** @var array{provider: string, mode: string, keys: array<string, mixed>, test_to: string}|false|null false = not read yet */
    private static array|false|null $active = false;

    private static int $readAt = 0;

    /** @var (\Closure(array{host: string, username: string, password: string}, Message, Envelope): void)|null tests see what would be sent */
    public static ?\Closure $sender = null;

    public function __construct(private readonly string $fallback)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'platform';
    }

    /** @return array{provider: string, mode: string, keys: array<string, mixed>, test_to: string}|null */
    public static function active(): ?array
    {
        if (self::$active === false || time() - self::$readAt > 60) {
            self::$readAt = time();
            try {
                $a = app(OutsideServices::class)->active(self::SERVICE);
                $test = array_values(array_filter($a['test_contacts'] ?? [], fn (string $c): bool => filter_var($c, FILTER_VALIDATE_EMAIL) !== false));
                self::$active = $a === null ? null : ['provider' => (string) $a['provider'], 'mode' => (string) $a['mode'], 'keys' => (array) $a['keys'], 'test_to' => (string) ($test[0] ?? '')];
            } catch (Throwable) {
                self::$active = null; // before the tables exist: the server's own mailer
            }
        }

        return self::$active;
    }

    public static function forget(): void
    {
        self::$active = false;
    }

    /**
     * The provider's SMTP server and sign-in.
     *
     * @param  array<string, mixed>  $keys
     * @return array{host: string, username: string, password: string}|null
     */
    public static function smtp(string $provider, array $keys): ?array
    {
        $s = fn (string $k): string => trim((string) ($keys[$k] ?? ''));

        return match ($provider) {
            'brevo' => ['host' => self::HOSTS['brevo'], 'username' => $s('smtp_login'), 'password' => $s('smtp_key')],
            // Postmark's SMTP uses the server API token as both the username and the password.
            'postmark_send' => ['host' => self::HOSTS['postmark_send'], 'username' => $s('server_token'), 'password' => $s('server_token')],
            'ses' => preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $s('region')) === 1
                ? ['host' => 'email-smtp.'.$s('region').'.amazonaws.com', 'username' => $s('smtp_username'), 'password' => $s('smtp_password')] : null,
            default => null,
        };
    }

    /**
     * Checks the sign-in works (used by "Check connection"): null when it does, otherwise what went wrong.
     *
     * @param  array<string, mixed>  $keys
     */
    public static function check(string $provider, array $keys): ?string
    {
        $smtp = self::smtp($provider, $keys);
        if ($smtp === null || $smtp['username'] === '' || $smtp['password'] === '') {
            return 'Fill in every field.';
        }
        try {
            $transport = self::transport($smtp);
            $transport->start();
            $transport->stop();

            return null;
        } catch (Throwable $e) {
            return 'The provider refused the sign-in. Check the login and key. ('.mb_substr($e->getMessage(), 0, 120).')';
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $active = self::active();
        $smtp = $active === null ? null : self::smtp($active['provider'], $active['keys']);
        $original = $message->getOriginalMessage();
        if ($active === null || $smtp === null || ! $original instanceof Message) {
            $this->fallback($original, $message->getEnvelope());

            return;
        }
        $keys = $active['keys'];
        $from = trim((string) ($keys['from_address'] ?? ''));
        $envelope = $message->getEnvelope();
        if ($original instanceof Email && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            // Send from the address verified with the provider; replies still reach whoever the email names.
            $name = trim((string) ($keys['from_name'] ?? '')) ?: (string) config('mail.from.name', 'RightAlly');
            if ($original->getReplyTo() === [] && $original->getFrom() !== [] && $original->getFrom()[0]->getAddress() !== $from) {
                $original->replyTo($original->getFrom()[0]);
            }
            $original->from(new Address($from, $name));
            $envelope = new Envelope(new Address($from), $envelope->getRecipients());
        }
        if ($active['mode'] !== 'live') {
            $test = $active['test_to'];
            if ($test === '') {
                return; // Test mode without a test contact: send nothing rather than reach real people
            }
            if ($original instanceof Email) {
                $real = implode(', ', array_map(fn (Address $a): string => $a->getAddress(), $envelope->getRecipients()));
                $original->subject('[TEST to '.$real.'] '.$original->getSubject());
            }
            $envelope = new Envelope($envelope->getSender(), [new Address($test)]);
        }
        if (self::$sender !== null) {
            (self::$sender)($smtp, $original, $envelope);

            return;
        }
        self::transport($smtp)->send($original, $envelope);
    }

    /** @param array{host: string, username: string, password: string} $smtp */
    private static function transport(array $smtp): EsmtpTransport
    {
        $t = new EsmtpTransport($smtp['host'], 587, false);
        $t->setUsername($smtp['username']);
        $t->setPassword($smtp['password']);

        return $t;
    }

    private function fallback(RawMessage $message, Envelope $envelope): void
    {
        $mailer = app(MailFactory::class)->mailer($this->fallback);
        if (method_exists($mailer, 'getSymfonyTransport')) {
            $mailer->getSymfonyTransport()->send($message, $envelope);
        }
    }
}
