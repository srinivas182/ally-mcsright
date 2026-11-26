<?php

declare(strict_types=1);

namespace Modules\Sites\Domains;

/**
 * DM2: where a web address points, from public DNS (a seam so tests and HQ checks can answer without the network).
 */
class DnsLookup
{
    /** The CNAME target of a host, lower case and without the final dot; null when it has none. */
    public function cname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME);
        $target = is_array($records) && isset($records[0]['target']) ? (string) $records[0]['target'] : '';

        return $target !== '' ? rtrim(strtolower($target), '.') : null;
    }

    /** @return list<string> the host's IPv4 addresses */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);

        return is_array($records) ? array_values(array_filter(array_map(fn (array $r): string => (string) ($r['ip'] ?? ''), $records))) : [];
    }
}
