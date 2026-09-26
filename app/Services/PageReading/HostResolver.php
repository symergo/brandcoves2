<?php

declare(strict_types=1);

namespace App\Services\PageReading;

/**
 * Every address a host name points at.
 *
 * A class of its own so the tests can answer DNS without the network: the
 * checks in {@see SafeFetch} are only worth anything if "this name resolves to
 * 10.0.0.5" can be proven to be refused, and that needs a resolver the test
 * controls.
 */
class HostResolver
{
    /** @return list<string> IPv4 and IPv6, empty when the name does not resolve */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
