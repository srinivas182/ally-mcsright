<?php

declare(strict_types=1);

namespace Modules\Crm\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;

/**
 * Email and phone checks for database health (LN4, E24, specs/grow/16), through the providers HQ chose in Outside
 * services: email checks with ZeroBounce (recommended), NeverBounce or Kickbox; phone checks with Twilio Lookup
 * (recommended), Telnyx or Numverify.
 *
 * - Email: valid, invalid (bounces) or risky (catch-all or unknown). Invalid addresses show in database health with
 *   a one-click list; campaigns and reports then skip them.
 * - Phone: mobile, landline, voip or invalid. Texts only reach mobiles, so a landline is shown on the contact.
 * - The name the phone network has on file (caller ID) is kept as a suggestion when the contact has no name; the
 *   agent decides whether to use it ("licensed fill-in").
 *
 * Each contact is checked at most every six months; up to 200 emails and 200 phones a day per brokerage, so costs
 * stay predictable.
 */
final class ContactChecks
{
    public const DAILY = 200;

    public const EVERY_DAYS = 180;

    public function __construct(private readonly TenantContext $tenants) {}

    /** Checks the next batch of emails. Returns how many were checked. */
    public function emails(int $limit = self::DAILY): int
    {
        $service = app(OutsideServices::class)->active('email_check', $this->tenants->id());
        if ($service === null) {
            return 0;
        }
        $n = 0;
        foreach ($this->due('primary_email', 'email_checked_at', $limit) as $c) {
            $status = rescue(fn (): ?string => self::emailStatus($service['provider'], $service['keys'], (string) $c->primary_email), null, true);
            if ($status === null) {
                continue;
            }
            $c->newQuery()->whereKey($c->id)->update(['email_status' => $status, 'email_checked_at' => now()]);
            if ($status === 'invalid' && $c->email_status !== 'invalid') {
                app(Timeline::class)->system($c, 'Email check: '.$c->primary_email.' bounces. Ask them for a new email.');
            }
            $n++;
        }

        return $n;
    }

    /** Checks the next batch of phones. Returns how many were checked. */
    public function phones(int $limit = self::DAILY): int
    {
        $service = app(OutsideServices::class)->active('phone_check', $this->tenants->id());
        if ($service === null) {
            return 0;
        }
        $n = 0;
        foreach ($this->due('primary_phone', 'phone_checked_at', $limit) as $c) {
            $r = rescue(fn (): ?array => self::phoneInfo($service['provider'], $service['keys'], (string) $c->primary_phone), null, true);
            if ($r === null) {
                continue;
            }
            $empty = trim($c->first_name.' '.$c->last_name) === '';
            $c->newQuery()->whereKey($c->id)->update(['phone_type' => $r['type'], 'phone_checked_at' => now(),
                'caller_name' => $empty && $r['name'] !== null ? mb_substr($r['name'], 0, 120) : null]);
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<string, string>  $keys
     */
    public static function emailStatus(string $provider, array $keys, string $email): ?string
    {
        $k = (string) ($keys['api_key'] ?? '');

        return match ($provider) {
            'zerobounce' => (function () use ($k, $email): ?string {
                $s = (string) Http::timeout(20)->get('https://api.zerobounce.net/v2/validate', ['api_key' => $k, 'email' => $email, 'ip_address' => ''])->throw()->json('status');

                return match ($s) {
                    'valid' => 'valid', 'invalid', 'spamtrap' => 'invalid', '' => null, default => 'risky'
                };
            })(),
            'neverbounce' => (function () use ($k, $email): ?string {
                $s = (string) Http::timeout(20)->get('https://api.neverbounce.com/v4/single/check', ['key' => $k, 'email' => $email])->throw()->json('result');

                return match ($s) {
                    'valid' => 'valid', 'invalid', 'disposable' => 'invalid', '' => null, default => 'risky'
                };
            })(),
            'kickbox' => (function () use ($k, $email): ?string {
                $s = (string) Http::timeout(20)->get('https://api.kickbox.com/v2/verify', ['email' => $email, 'apikey' => $k])->throw()->json('result');

                return match ($s) {
                    'deliverable' => 'valid', 'undeliverable' => 'invalid', '' => null, default => 'risky'
                };
            })(),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $keys
     * @return array{type: string, name: string|null}|null
     */
    public static function phoneInfo(string $provider, array $keys, string $phone): ?array
    {
        $e164 = '+'.ltrim((string) preg_replace('/[^\d+]/', '', str_starts_with($phone, '+') ? $phone : '+1'.preg_replace('/\D/', '', $phone)), '+');
        $type = fn (string $t): string => match (true) {
            in_array($t, ['mobile', 'wireless', 'cellular'], true) => 'mobile',
            in_array($t, ['landline', 'fixed_line', 'fixed line'], true) => 'landline',
            $t === '' => 'invalid',
            default => 'voip',
        };

        switch ($provider) {
            case 'twilio_lookup':
                $r = Http::timeout(20)->withBasicAuth((string) ($keys['sid'] ?? ''), (string) ($keys['token'] ?? ''))
                    ->get('https://lookups.twilio.com/v2/PhoneNumbers/'.rawurlencode($e164), ['Fields' => 'line_type_intelligence,caller_name']);
                if ($r->status() === 404 || $r->json('valid') === false) {
                    return ['type' => 'invalid', 'name' => null];
                }
                $r->throw();
                $t = strtolower((string) $r->json('line_type_intelligence.type'));

                return ['type' => $type(str_contains($t, 'voip') ? 'voip' : $t), 'name' => self::name($r->json('caller_name.caller_name'))];
            case 'telnyx':
                $r = Http::timeout(20)->withToken((string) ($keys['api_key'] ?? ''))->get('https://api.telnyx.com/v2/number_lookup/'.rawurlencode($e164), ['type' => 'carrier']);
                if ($r->status() === 404 || $r->status() === 422) {
                    return ['type' => 'invalid', 'name' => null];
                }
                $r->throw();

                return ['type' => $type(strtolower((string) $r->json('data.carrier.type'))), 'name' => self::name($r->json('data.caller_name.caller_name'))];
            case 'numverify':
                $r = Http::timeout(20)->get('https://apilayer.net/api/validate', ['access_key' => (string) ($keys['api_key'] ?? ''), 'number' => ltrim($e164, '+')])->throw();
                if ($r->json('valid') !== true) {
                    return ['type' => 'invalid', 'name' => null];
                }

                return ['type' => $type(strtolower((string) $r->json('line_type'))), 'name' => null];
            default:
                return null;
        }
    }

    /** A caller-ID name in normal case ("SMITH JOHN" stays as given; business names too), or null. */
    private static function name(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' || strlen($v) < 3 ? null : mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
    }

    /** @return iterable<Contact> */
    private function due(string $field, string $checked, int $limit): iterable
    {
        return Contact::query()->whereNotNull($field)->where('lifecycle', '!=', 'archived')
            ->where(fn ($q) => $q->whereNull($checked)->orWhere($checked, '<', CarbonImmutable::now()->subDays(self::EVERY_DAYS)))
            ->orderBy($checked)->orderBy('id')->limit($limit)->get(); // never checked first (nulls sort first)
    }
}
