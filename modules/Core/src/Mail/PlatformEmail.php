<?php

declare(strict_types=1);

namespace Modules\Core\Mail;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\Http;
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

    /** EM2: Brevo's transactional email API. */
    public const BREVO_API = 'https://api.brevo.com/v3';

    /** @var (\Closure(array<string, string>, Message, Envelope): void)|null tests see what would be sent (an SMTP sign-in, or ['api' => 'brevo']) */
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
        if (self::usesBrevoApi($provider, $keys)) {
            return self::checkBrevo($keys);
        }
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
        $api = $active !== null && self::usesBrevoApi($active['provider'], $active['keys']);
        $smtp = $active === null || $api ? null : self::smtp($active['provider'], $active['keys']);
        $original = $message->getOriginalMessage();
        if ($active === null || ($smtp === null && ! $api) || ! $original instanceof Message || ($api && ! $original instanceof Email)) {
            $this->fallback($original, $message->getEnvelope());

            return;
        }
        $keys = $active['keys'];
        $from = trim((string) ($keys['from_address'] ?? ''));
        $envelope = $message->getEnvelope();
        if ($original instanceof Email && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            // Send from the address verified with the provider; replies still reach whoever the email names.
            $name = trim((string) ($keys['from_name'] ?? '')) ?: (string) config('mail.from.name', 'RightAlly');
            // EM4: a brokerage's emails show its name ("MCS Realty via RightAlly"), so people know who wrote to them.
            $brand = MailBrand::current();
            if (! $brand['platform'] && trim($brand['name']) !== '') {
                $name = mb_substr(trim($brand['name']), 0, 60).' via '.$name;
            }
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
            (self::$sender)($smtp ?? ['api' => 'brevo'], $original, $envelope);

            return;
        }
        if ($api) {
            self::sendWithBrevo(trim((string) ($active['keys']['api_key'] ?? '')), $original, $envelope);

            return;
        }
        self::transport($smtp)->send($original, $envelope);
    }

    /**
     * EM2: Brevo goes through its API when an API key is saved. Keys saved before EM2 (SMTP login and key) keep working
     * over SMTP until HQ saves an API key.
     *
     * @param  array<string, mixed>  $keys
     */
    public static function usesBrevoApi(string $provider, array $keys): bool
    {
        return $provider === 'brevo' && trim((string) ($keys['api_key'] ?? '')) !== '';
    }

    /**
     * The Brevo API body for one email: sender, recipients, reply-to, subject, HTML and text, attachments.
     *
     * @return array<string, mixed>
     */
    public static function brevoPayload(Email $email, Envelope $envelope): array
    {
        $body = fn (mixed $b): ?string => is_resource($b) ? (string) stream_get_contents($b) : (is_string($b) ? $b : null);
        $from = $email->getFrom()[0] ?? $envelope->getSender();
        $payload = [
            'sender' => array_filter(['email' => $from->getAddress(), 'name' => $from->getName()]),
            'to' => array_map(fn (Address $a): array => ['email' => $a->getAddress()], $envelope->getRecipients()),
            'subject' => (string) $email->getSubject(),
        ];
        if (($reply = $email->getReplyTo()[0] ?? null) !== null) {
            $payload['replyTo'] = array_filter(['email' => $reply->getAddress(), 'name' => $reply->getName()]);
        }
        $html = $body($email->getHtmlBody());
        $text = $body($email->getTextBody());
        if ($html !== null && $html !== '') {
            $payload['htmlContent'] = $html;
        }
        if ($text !== null && $text !== '') {
            $payload['textContent'] = $text;
        }
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            $payload['textContent'] = ' ';
        }
        $files = [];
        foreach ($email->getAttachments() as $part) {
            $files[] = ['name' => $part->getFilename() ?? 'attachment', 'content' => base64_encode($part->getBody())];
        }
        if ($files !== []) {
            $payload['attachment'] = $files;
        }

        return $payload;
    }

    private static function sendWithBrevo(string $key, Email $email, Envelope $envelope): void
    {
        $response = Http::timeout(20)->withHeaders(['api-key' => $key])->acceptJson()
            ->post(self::BREVO_API.'/smtp/email', self::brevoPayload($email, $envelope));
        if (! $response->successful()) {
            throw new \RuntimeException('Brevo did not send the email ('.$response->status().'): '.mb_substr((string) $response->json('message', $response->body()), 0, 200));
        }
    }

    /**
     * "Check connection" for Brevo's API: the key works, and "Send from" is a verified sender (or on a verified domain).
     * Reads only; nothing is sent.
     *
     * @param  array<string, mixed>  $keys
     */
    private static function checkBrevo(array $keys): ?string
    {
        $key = trim((string) ($keys['api_key'] ?? ''));
        $from = strtolower(trim((string) ($keys['from_address'] ?? '')));
        if ($key === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return 'Fill in the API key and the "Send from" address.';
        }
        $brevo = fn (string $path) => Http::timeout(15)->withHeaders(['api-key' => $key])->acceptJson()->get(self::BREVO_API.$path);
        try {
            $account = $brevo('/account');
            if ($account->status() === 401) {
                return 'Brevo did not accept the API key, or blocked this server\'s IP address. Copy the key again, or add the server\'s IP under Security, then Authorised IPs, in Brevo.';
            }
            if (! $account->successful()) {
                return 'Brevo answered with an error ('.$account->status().'). Try again in a minute.';
            }
            $senders = collect((array) $brevo('/senders')->json('senders'));
            if ($senders->contains(fn (mixed $s): bool => is_array($s) && strtolower((string) ($s['email'] ?? '')) === $from && ($s['active'] ?? false) === true)) {
                return null;
            }
            $domain = substr($from, strpos($from, '@') + 1);
            $domains = collect((array) $brevo('/senders/domains')->json('domains'));
            if ($domains->contains(fn (mixed $d): bool => is_array($d) && strtolower((string) ($d['domain_name'] ?? '')) === $domain && (($d['authenticated'] ?? false) === true || ($d['verified'] ?? false) === true))) {
                return null;
            }

            return "The API key works, but {$from} is not a verified sender in Brevo. Add it under Senders, Domains and dedicated IPs and click the link Brevo emails you, or verify the domain {$domain}.";
        } catch (Throwable) {
            return 'Could not reach Brevo. Try again in a minute.';
        }
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
