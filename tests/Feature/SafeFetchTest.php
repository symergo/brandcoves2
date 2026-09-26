<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\HostResolver;
use App\Services\PageReading\SafeFetch;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The server requests a visitor's URL only through SafeFetch, and these are
 * the ways that request could be turned against us.
 *
 * DNS is answered by the test (a fake HostResolver), so "this name points at
 * 10.0.0.5" is something we can prove is refused without a network.
 */
class SafeFetchTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $dns = &$this->dns;
        $this->app->instance(HostResolver::class, new class($dns) extends HostResolver
        {
            /** @param  array<string, list<string>>  $dns */
            public function __construct(private array &$dns) {}

            public function addresses(string $host): array
            {
                // An IP literal resolves to itself, as the real resolver's does.
                return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->dns[$host] ?? []);
            }
        });
    }

    #[Test]
    public function a_public_page_is_fetched(): void
    {
        $this->dns['shop.example'] = ['93.184.216.34'];
        Http::fake(['https://shop.example/p' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $page = $this->fetch()->get('https://shop.example/p', ['text/html'], 1000);

        $this->assertSame('<html></html>', $page->body);
        $this->assertSame('text/html', $page->contentType);
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function a_url_that_could_reach_something_private_is_refused(string $url, string $reason): void
    {
        $this->dns = [
            'shop.example' => ['93.184.216.34'],
            'intranet.example' => ['10.0.0.5'],
            'metadata.example' => ['169.254.169.254'],
            'split.example' => ['93.184.216.34', '127.0.0.1'],
            'mapped.example' => ['::ffff:127.0.0.1'],
            'v6local.example' => ['fd00::1'],
            'cgnat.example' => ['100.64.1.1'],
        ];

        try {
            $this->fetch()->get($url, ['text/html'], 1000);
            $this->fail("{$url} was fetched.");
        } catch (FetchRefused $e) {
            $this->assertSame($reason, $e->reason);
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedUrls(): array
    {
        return [
            'plain http' => ['http://shop.example/p', 'scheme'],
            'javascript' => ['javascript:alert(1)', 'scheme'],
            'another port' => ['https://shop.example:6379/', 'port'],
            'credentials trick' => ['https://shop.example@intranet.example/', 'credentials'],
            'localhost' => ['https://localhost/', 'host'],
            'a .internal name' => ['https://db.internal/', 'host'],
            'private address' => ['https://intranet.example/', 'private'],
            'cloud metadata' => ['https://metadata.example/latest/', 'private'],
            'one of two addresses private' => ['https://split.example/', 'private'],
            'IPv4 written as IPv6' => ['https://mapped.example/', 'private'],
            'IPv6 unique local' => ['https://v6local.example/', 'private'],
            'carrier-grade NAT' => ['https://cgnat.example/', 'private'],
            'IP literal, private' => ['https://127.0.0.1/', 'private'],
            'does not resolve' => ['https://nowhere.example/', 'dns'],
        ];
    }

    #[Test]
    public function a_redirect_to_a_private_address_is_refused_at_the_hop(): void
    {
        // The simplest bypass of a check made only once.
        $this->dns = ['shop.example' => ['93.184.216.34'], 'intranet.example' => ['10.0.0.5']];
        Http::fake(['https://shop.example/p' => Http::response('', 302, ['Location' => 'https://intranet.example/admin'])]);

        $this->expectExceptionObject(new FetchRefused('private', 'intranet.example → 10.0.0.5'));

        $this->fetch()->get('https://shop.example/p', ['text/html'], 1000);
    }

    #[Test]
    public function a_relative_redirect_is_followed_and_checked(): void
    {
        $this->dns['shop.example'] = ['93.184.216.34'];
        Http::fake([
            'https://shop.example/old' => Http::response('', 301, ['Location' => '/new']),
            'https://shop.example/new' => Http::response('<p>here</p>', 200, ['Content-Type' => 'text/html']),
        ]);

        $page = $this->fetch()->get('https://shop.example/old', ['text/html'], 1000);

        $this->assertSame('https://shop.example/new', $page->url);
    }

    #[Test]
    public function redirects_stop_after_three(): void
    {
        $this->dns['shop.example'] = ['93.184.216.34'];
        Http::fake(['https://shop.example/*' => Http::response('', 302, ['Location' => '/again'])]);

        $this->expectExceptionObject(new FetchRefused('too_many_redirects'));

        $this->fetch()->get('https://shop.example/start', ['text/html'], 1000);
    }

    #[Test]
    public function a_body_over_the_cap_and_a_wrong_type_are_refused(): void
    {
        $this->dns['shop.example'] = ['93.184.216.34'];
        Http::fake([
            'https://shop.example/big' => Http::response(str_repeat('x', 2000), 200, ['Content-Type' => 'text/html']),
            'https://shop.example/file.zip' => Http::response('PK', 200, ['Content-Type' => 'application/zip']),
        ]);

        try {
            $this->fetch()->get('https://shop.example/big', ['text/html'], 1000);
            $this->fail('Oversized body accepted.');
        } catch (FetchRefused $e) {
            $this->assertSame('too_large', $e->reason);
        }

        try {
            $this->fetch()->get('https://shop.example/file.zip', ['text/html'], 1000);
            $this->fail('Zip accepted as a page.');
        } catch (FetchRefused $e) {
            $this->assertSame('content_type', $e->reason);
        }
    }

    private function fetch(): SafeFetch
    {
        return $this->app->make(SafeFetch::class);
    }
}
