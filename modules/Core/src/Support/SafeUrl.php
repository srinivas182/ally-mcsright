<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Illuminate\Validation\ValidationException;

/**
 * Addresses RightAlly sends to on a customer's behalf (webhooks, specs/connect/01; audit streams; website checks)
 * must be public HTTPS (moved to Core in Sprint 61): no internal or reserved IP addresses (server-side
 * request forgery), no user:password in the URL, standard ports only. The host is resolved when saving and again
 * at every delivery, and the delivery connects to the checked IP (so DNS can't be switched to an internal address).
 */
final class SafeUrl
{
    /**
     * @return array{url: string, host: string, port: int, ip: string}
     *
     * @throws ValidationException
     */
    /**
     * @param  list<int>  $ports  allowed ports (Splunk's event collector listens on 8088)
     */
    public static function check(string $url, array $ports = [443, 8443]): array
    {
        $fail = static fn (string $m) => ValidationException::withMessages(['url' => $m]);
        $p = parse_url(trim($url));
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || ! isset($p['host']) || $p['host'] === '') {
            throw $fail('Use an https:// address.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw $fail('Remove the user name and password from the address.');
        }
        $port = (int) ($p['port'] ?? 443);
        if (! in_array($port, $ports, true)) {
            throw $fail('Use the standard HTTPS port.');
        }
        $host = strtolower($p['host']);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw $fail('Use a public web address, not an IP address or an internal name.');
        }
        /** @var callable(string): list<string> $resolve */
        $resolve = app()->bound('connect.dns') ? app('connect.dns') : static fn (string $h): array => gethostbynamel($h) ?: [];
        $ips = $resolve($host);
        if ($ips === []) {
            throw $fail('That address could not be found.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw $fail('That address points to a private network.');
            }
        }

        return ['url' => trim($url), 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }
}
