<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only way the server requests a URL a visitor gave it.
 *
 * Until 2026-09-26 the rule was simpler: we never requested such a URL at all
 * (see the history in docs/features/pasted-links.md). Reading a shop's page is
 * now part of the strategy, and the reason the old rule existed still stands —
 * a server that fetches whatever it is handed can be pointed at itself, at the
 * database next door, or at the cloud provider's metadata address. So every
 * rule below is about *where* the request goes, and each one closes a
 * specific way round the one before it:
 *
 * - **`https:` on port 443 only.** No `http:`, no `file:`, no `gopher:`, no
 *   `https://host:6379`. A shop's product page is on 443.
 * - **Every address the name resolves to must be public.** Not just the first:
 *   a name with one public and one private record would otherwise be a coin
 *   toss we lose half the time.
 * - **The request goes to the address we checked.** Resolving, checking, and
 *   then letting curl resolve again is the classic hole (DNS rebinding: the
 *   second answer is `127.0.0.1`). `CURLOPT_RESOLVE` pins the connection to the
 *   checked address.
 * - **Redirects are followed by hand, at most three**, and every hop goes
 *   through all of the above again. A public page that answers `302 Location:
 *   http://10.0.0.1/` is the simplest bypass of a check made only once.
 * - **A size cap and a time limit**, so a link to a 4 GB file or a server that
 *   drips one byte a second costs us nothing.
 */
class SafeFetch
{
    public const MAX_REDIRECTS = 3;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  list<string>  $contentTypes  accepted media types, e.g. `text/html`
     *
     * @throws FetchRefused
     */
    public function get(string $url, array $contentTypes, int $maxBytes): FetchedResponse
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $address] = $this->check($current);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? "[{$address}]" : $address)]],
                    // Aborts a body that grows past the cap while it downloads,
                    // rather than after we have already held all of it.
                    'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new FetchRefused('too_large');
                        }
                    },
                ])
                    ->withHeaders([
                        /*
                         * Says who we are, in the form every well-behaved
                         * crawler uses ("Mozilla/5.0 (compatible; Name/1.0;
                         * +url)", as Googlebot and Bingbot do). The bare
                         * "GiftCovesBot/1.0 (+url)" was refused outright by
                         * shops' bot protection (de Bijenkorf and Coolblue
                         * answered 403, 2026-09-26) while this same honest
                         * name in the conventional form was let in. We never
                         * pretend to be a browser: that is blocked anyway, and
                         * it would be a lie. A shop that still refuses us is
                         * answered by the item staying as it was typed.
                         */
                        'User-Agent' => 'Mozilla/5.0 (compatible; GiftCovesBot/1.0; +'.rtrim((string) config('app.url'), '/').')',
                        'Accept-Language' => 'nl-BE,nl;q=0.9,fr;q=0.8,en;q=0.7',
                        'Accept' => implode(', ', $contentTypes),
                    ])
                    ->timeout((int) config('giftcoves.page_reading.timeout', 5))
                    ->connectTimeout((int) config('giftcoves.page_reading.connect_timeout', 3))
                    ->get($current);
            } catch (FetchRefused $e) {
                throw $e;
            } catch (Throwable $e) {
                // Guzzle wraps an exception thrown from `progress`; unwrap it so
                // the reason survives, and call everything else unreachable.
                if ($e->getPrevious() instanceof FetchRefused) {
                    throw $e->getPrevious();
                }

                throw new FetchRefused('unreachable', $e->getMessage());
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new FetchRefused('bad_redirect');
                }

                $current = self::absolute($location, $current);

                continue;
            }

            if ($status !== 200) {
                throw new FetchRefused('status', (string) $status);
            }

            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            if (! in_array($type, $contentTypes, true)) {
                throw new FetchRefused('content_type', $type);
            }

            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                throw new FetchRefused('too_large');
            }

            return new FetchedResponse(url: $current, contentType: $type, body: $body);
        }

        throw new FetchRefused('too_many_redirects');
    }

    /**
     * The host to connect to and the checked address to connect it to.
     *
     * @return array{0: string, 1: string}
     *
     * @throws FetchRefused
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new FetchRefused('scheme');
        }

        // Credentials in a URL are never a shop's product page, and they are
        // how `https://public.example@10.0.0.1/` reads to a human as the first
        // host while connecting to the second.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchRefused('credentials');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new FetchRefused('port');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|internal|lan|home|corp)$/', $host)) {
            throw new FetchRefused('host', $host);
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new FetchRefused('dns', $host);
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new FetchRefused('private', "{$host} → {$address}");
            }
        }

        return [$host, $addresses[0]];
    }

    /**
     * Is this an address on the public internet?
     *
     * PHP's two filter flags cover the RFC 1918 ranges, loopback, link-local
     * (which includes the `169.254.169.254` metadata address), `0.0.0.0/8`,
     * multicast and the IPv6 equivalents. The ranges added by hand are the ones
     * they do not: carrier-grade NAT, the IETF protocol block, the benchmarking
     * range — none of which hosts a shop, and some of which route to
     * infrastructure. An IPv4 address written as IPv6 (`::ffff:127.0.0.1`) is
     * checked as the IPv4 address it is.
     */
    public static function isPublic(string $address): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m)) {
            $address = $m[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/3'] as $range) {
                if (self::inRange($address, $range)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6: refuse the IPv4-compatible and NAT64 prefixes, which can carry
        // a private IPv4 address inside a public-looking IPv6 one.
        $lower = strtolower($address);

        return ! str_starts_with($lower, '64:ff9b:') && ! str_starts_with($lower, '::');
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }

    /** A redirect's `Location`, which may be relative, made absolute. */
    public static function absolute(string $location, string $base): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($dir === '' ? '/' : $dir).$location;
    }
}
