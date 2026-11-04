<?php

use Carbon\CarbonImmutable;
use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\ToolException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function downloaderWithPublicDns(): SourceDownloader
{
    // Resolver injected so tests never touch real DNS; 93.184.216.34 is public.
    return new SourceDownloader(fn (string $host) => ['93.184.216.34']);
}

it('downloads a file and derives the basename from the final url', function () {
    Http::fake(['https://images.example.com/*' => Http::response('PNGBYTES', 200)]);

    [$contents, $basename] = downloaderWithPublicDns()->download('https://images.example.com/photos/hero.png');

    expect($contents)->toBe('PNGBYTES');
    expect($basename)->toBe('hero.png');
});

it('percent-decodes the derived basename', function () {
    Http::fake(['https://images.example.com/*' => Http::response('BYTES', 200)]);

    [, $basename] = downloaderWithPublicDns()->download('https://images.example.com/hero%20image.png');

    expect($basename)->toBe('hero image.png');
});

it('rejects non-http schemes', function () {
    downloaderWithPublicDns()->download('ftp://images.example.com/hero.png');
})->throws(ToolException::class, 'source_url must use http or https');

it('rejects unparseable urls', function () {
    downloaderWithPublicDns()->download('not a url');
})->throws(ToolException::class, 'not a valid absolute URL');

it('rejects literal private, loopback, link-local, CGN and IPv6-internal addresses', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/x.png',
    'http://10.0.0.5/x.png',
    'http://172.16.0.1/x.png',
    'http://192.168.1.1/x.png',
    'http://169.254.169.254/latest/meta-data', // cloud metadata endpoint
    'http://100.64.0.1/x.png',                 // carrier-grade NAT
    'http://[::1]/x.png',
    'http://[fc00::1]/x.png',
    'http://[fe80::1]/x.png',
]);

it('rejects NAT64 addresses whose IPv4 address is private', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://[64:ff9b::7f00:1]/x.png',
    'cloud metadata' => 'http://[64:ff9b::a9fe:a9fe]/x.png',
    'carrier-grade NAT' => 'http://[64:ff9b::6440:1]/x.png',
    'benchmarking' => 'http://[64:ff9b::c612:1]/x.png',
    'Azure wire server' => 'http://[64:ff9b::a83f:8110]/x.png',
]);

it('rejects local-use NAT64, 6to4 and Teredo addresses whatever IPv4 address they carry', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'local-use NAT64 of a public address' => 'http://[64:ff9b:1::808:808]/x.png',
    '6to4 of loopback' => 'http://[2002:7f00:1::]/x.png',
    '6to4 of a public address' => 'http://[2002:808:808::1]/x.png',
    'Teredo' => 'http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/x.png',
]);

it('refuses what IANA marks as not globally reachable, multicast, and deprecated ranges', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'IETF protocol assignments, old Oracle Cloud metadata' => 'http://192.0.0.192/x.png',
    'TEST-NET-1' => 'http://192.0.2.1/x.png',
    'deprecated 6to4 relay anycast' => 'http://192.88.99.2/x.png',
    'benchmarking, lower half' => 'http://198.18.0.1/x.png',
    'benchmarking, upper half' => 'http://198.19.255.254/x.png',
    'TEST-NET-2' => 'http://198.51.100.1/x.png',
    'TEST-NET-3' => 'http://203.0.113.1/x.png',
    'multicast, first' => 'http://224.0.0.1/x.png',
    'multicast, test network' => 'http://233.252.0.1/x.png',
    'multicast, last' => 'http://239.255.255.255/x.png',
    'IPv6 discard-only' => 'http://[100::1]/x.png',
    'IPv6 dummy prefix' => 'http://[100:0:0:1::1]/x.png',
    'IPv6 IETF protocol assignments' => 'http://[2001:1::1]/x.png',
    'IPv6 benchmarking' => 'http://[2001:2::1]/x.png',
    'IPv6 documentation' => 'http://[2001:db8::1]/x.png',
    'IPv6 documentation, 2024 block' => 'http://[3fff::1]/x.png',
    'SRv6 SIDs' => 'http://[5f00::1]/x.png',
    'deprecated site-local' => 'http://[fec0::1]/x.png',
    'IPv6 multicast' => 'http://[ff02::1]/x.png',
    'IPv4-translated' => 'http://[::ffff:0:a00:1]/x.png',
    'unallocated IPv6' => 'http://[4000::1]/x.png',
]);

it('refuses a refused address however it is spelled', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'uppercase scheme' => 'HTTP://198.18.0.1/x.png',
    'with credentials' => 'http://user:pass@198.18.0.1/x.png',
    'with a port' => 'http://198.18.0.1:8080/x.png',
    'uppercase IPv6' => 'http://[FEC0::1]/x.png',
    'expanded IPv6' => 'http://[fec0:0:0:0:0:0:0:1]/x.png',
    'NAT64, expanded' => 'http://[64:FF9B:0:0:0:0:c612:1]/x.png',
]);

it('refuses the Azure wire server, a public address that reaches the platform from inside Azure', function () {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download('http://168.63.129.16/machine'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
});

it('keeps fetching public addresses right beside the refused ranges', function (string $url) {
    Http::fake(['*' => Http::response('BYTES', 200)]);

    [$contents] = (new SourceDownloader)->download($url);

    expect($contents)->toBe('BYTES');
})->with([
    'just below 192.0.0.0/24' => 'http://191.255.255.254/x.png',
    'just above 192.0.0.0/24' => 'http://192.0.1.1/x.png',
    'just above 192.0.2.0/24' => 'http://192.0.3.1/x.png',
    'just below 192.88.99.0/24' => 'http://192.88.98.1/x.png',
    'just below 198.18.0.0/15' => 'http://198.17.255.254/x.png',
    'just above 198.18.0.0/15' => 'http://198.20.0.1/x.png',
    'just above 203.0.113.0/24' => 'http://203.0.114.1/x.png',
    'just below multicast' => 'http://223.255.255.254/x.png',
    'just below the Azure wire server' => 'http://168.63.129.15/x.png',
    'just above the Azure wire server' => 'http://168.63.129.17/x.png',
    'first global unicast IPv6' => 'http://[2000::1]/x.png',
    'just above 2001::/23' => 'http://[2001:200::1]/x.png',
    'just above 2002::/16' => 'http://[2003::1]/x.png',
    'a real public resolver' => 'http://[2606:4700:4700::1111]/x.png',
    'just below 3fff::/20' => 'http://[3ffe:ffff:ffff:ffff:ffff:ffff:ffff:ffff]/x.png',
    'just above 3fff::/20' => 'http://[3fff:1000::1]/x.png',
]);

it('refuses a host when any resolved record is in a special-purpose range', function (array $records) {
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => $records);

    expect(fn () => $downloader->download('https://innocent-looking.example.com/x.png'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'benchmarking only' => [['198.18.0.1']],
    'public first, then benchmarking' => [['93.184.216.34', '198.18.0.1']],
    'public first, then the Azure wire server' => [['93.184.216.34', '168.63.129.16']],
    'public first, then site-local' => [['93.184.216.34', 'fec0::1']],
]);

it('refuses a redirect into a special-purpose range', function (string $location) {
    Http::preventStrayRequests();
    Http::fake([
        'https://images.example.com/*' => Http::response('', 302, ['Location' => $location]),
    ]);

    expect(fn () => downloaderWithPublicDns()->download('https://images.example.com/a.png'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertSentCount(1);
})->with([
    'benchmarking' => 'http://198.18.0.1/x.png',
    'Azure wire server' => 'http://168.63.129.16/machine',
    'site-local' => 'http://[fec0::1]/x.png',
]);

it('accepts the NAT64 address DNS64 returns for a public IPv4 host', function () {
    Http::fake(['https://images.example.com/*' => Http::response('PNGBYTES', 200)]);

    $downloader = new SourceDownloader(fn (string $host) => ['93.184.216.34', '64:ff9b::5db8:d822']);

    [$contents] = $downloader->download('https://images.example.com/hero.png');

    expect($contents)->toBe('PNGBYTES');
});

it('rejects hosts that RESOLVE to a private address', function () {
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => ['10.0.0.7']);

    expect(fn () => $downloader->download('https://innocent-looking.example.com/x.png'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
});

it('rejects unresolvable hosts', function () {
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => []);

    expect(fn () => $downloader->download('https://nope.example.com/x.png'))
        ->toThrow(ToolException::class, "could not resolve host 'nope.example.com'");
});

it('enforces the source allowlist when configured', function () {
    config(['statamic.mcp.uploads.source_allowlist' => ['images.example.com']]);
    Http::fake(['https://images.example.com/*' => Http::response('OK', 200)]);

    [$contents] = downloaderWithPublicDns()->download('https://images.example.com/a.png');
    expect($contents)->toBe('OK');

    expect(fn () => downloaderWithPublicDns()->download('https://evil.example.com/a.png'))
        ->toThrow(ToolException::class, "host 'evil.example.com' is not in the configured source allowlist");
});

it('refuses a listed host that resolves to a refused address, because the allowlist narrows the guard', function () {
    config(['statamic.mcp.uploads.source_allowlist' => ['images.example.com']]);
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => ['198.18.0.1']);

    expect(fn () => $downloader->download('https://images.example.com/a.png'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
});

it('asks curl to connect to the validated address whatever host it reads from the url, because the guard and curl parse hosts differently', function (string $url, array $resolved, string $target) {
    $curlOptions = null;

    Http::fake(function ($request, array $options) use (&$curlOptions) {
        $curlOptions = $options['curl'];

        return Http::response('BYTES', 200);
    });

    (new SourceDownloader(fn (string $host) => $resolved))->download($url);

    expect($curlOptions)->toBe([CURLOPT_CONNECT_TO => [$target]]);
})->with([
    'plain host, https' => ['https://images.example.com/a.png', ['93.184.216.34'], '::93.184.216.34:443'],
    'plain host, http' => ['http://images.example.com/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
    'explicit port' => ['http://images.example.com:8080/a.png', ['93.184.216.34'], '::93.184.216.34:8080'],
    'IPv6 result' => ['https://images.example.com/a.png', ['2606:4700:4700::1111'], '::[2606:4700:4700::1111]:443'],
    'literal IPv4' => ['http://8.8.8.8/a.png', [], '::8.8.8.8:80'],
    'literal IPv6' => ['http://[2606:4700:4700::1111]/a.png', [], '::[2606:4700:4700::1111]:80'],
    'url template that rewrites the host' => ['http://x{a}.attacker.example/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
    'leading dash that a resolve entry reads as removal' => ['http://-x.attacker.example/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
    'decimal host curl reads as IPv4' => ['http://2130706433/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
    'short host curl reads as IPv4' => ['http://127.1/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
    'hex host curl reads as IPv4' => ['http://0x7f.1/a.png', ['93.184.216.34'], '::93.184.216.34:80'],
]);

it('really connects to the pinned address, because Http::fake never runs curl and a site can make Guzzle skip curl', function (array $globalOptions) {
    $listener = @stream_socket_server('tcp://127.0.0.1:0');

    if ($listener === false) {
        test()->markTestSkipped('this environment cannot bind a loopback port');
    }

    $port = (int) parse_url('tcp://'.stream_socket_get_name($listener, false), PHP_URL_PORT);
    fclose($listener);

    $docroot = sys_get_temp_dir().'/mcp-pin-'.Str::random(8);
    File::ensureDirectoryExists($docroot);
    File::put($docroot.'/pinned.txt', 'PINNED');

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot]);
    $server->start();

    try {
        retry(50, function () use ($port) {
            fclose(fsockopen('127.0.0.1', $port, timeout: 0.1));
        }, 100);

        Http::globalOptions(['proxy' => '', ...$globalOptions]);

        $response = (new ReflectionMethod(SourceDownloader::class, 'fetch'))
            ->invoke(new SourceDownloader, "http://not-resolvable.invalid:{$port}/pinned.txt", '127.0.0.1', 1024, now()->addSeconds(5));

        expect($response->body())->toBe('PINNED');
    } finally {
        $server->stop(0);
        File::deleteDirectory($docroot);
    }
})->with([
    'default options' => [[]],
    'a site that streams every request' => [['stream' => true]],
]);

it('revalidates every redirect hop and refuses a redirect to a private address', function () {
    Http::fake([
        'https://images.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'private or reserved address');

it('follows a valid redirect with a relative Location and derives the basename from the final url', function () {
    Http::fake([
        'https://images.example.com/old.png' => Http::response('', 302, ['Location' => '/moved/hero2.png']),
        'https://images.example.com/moved/hero2.png' => Http::response('BYTES', 200),
    ]);

    [$contents, $basename] = downloaderWithPublicDns()->download('https://images.example.com/old.png');

    expect($contents)->toBe('BYTES');
    expect($basename)->toBe('hero2.png');
});

it('rejects a redirect without a Location header', function () {
    Http::fake(['https://images.example.com/*' => Http::response('', 302)]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'redirected without a Location header');

it('follows at most 3 redirects', function () {
    Http::fake([
        'https://images.example.com/*' => Http::response('', 302, ['Location' => 'https://images.example.com/again.png']),
    ]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'redirected more than 3 times');

it('gives all redirect hops one shared 15-second budget', function () {
    $this->freezeTime();
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];
        $this->travel(5)->seconds();

        return Http::response('', 302, ['Location' => 'https://images.example.com/again.png']);
    });

    expect(fn () => downloaderWithPublicDns()->download('https://images.example.com/a.png'))
        ->toThrow(ToolException::class, 'source_url took longer than 15 seconds');

    expect($timeouts)->toBe([15.0, 10.0, 5.0]);
    Http::assertSentCount(3);
});

it('reports a hop that curl cuts off at the deadline as the budget running out', function () {
    $this->freezeTime();
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];

        if (count($timeouts) === 1) {
            $this->travel(14_500)->milliseconds();

            return Http::response('', 302, ['Location' => 'https://images.example.com/stalls.png']);
        }

        $this->travel(500)->milliseconds();

        return Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 500 milliseconds with 0 bytes received',
            $request->toPsrRequest(),
        ));
    });

    expect(fn () => downloaderWithPublicDns()->download('https://images.example.com/a.png'))
        ->toThrow(ToolException::class, 'source_url took longer than 15 seconds');

    expect($timeouts)->toBe([15.0, 0.5]);
});

it('stops instead of starting a hop with under 100 ms left, because curl reads a 0 ms timeout as none', function () {
    $this->freezeTime();

    Http::fake(function () {
        $this->travel(14_950)->milliseconds();

        return Http::response('', 302, ['Location' => 'https://images.example.com/moved.png']);
    });

    expect(fn () => downloaderWithPublicDns()->download('https://images.example.com/a.png'))
        ->toThrow(ToolException::class, 'source_url took longer than 15 seconds');

    Http::assertSentCount(1);
});

it('downloads in a host app that configures immutable dates', function () {
    Date::use(CarbonImmutable::class);
    Http::fake(['https://images.example.com/*' => Http::response('PNGBYTES', 200)]);

    try {
        [$contents] = downloaderWithPublicDns()->download('https://images.example.com/hero.png');
    } finally {
        Date::useDefault();
    }

    expect($contents)->toBe('PNGBYTES');
});

it('lets a programming error through, because only a transport failure is the fault of the url', function () {
    Http::fake(fn () => throw new TypeError('a bug, not a bad url'));

    expect(fn () => downloaderWithPublicDns()->download('https://images.example.com/a.png'))
        ->toThrow(TypeError::class, 'a bug, not a bad url');
});

it('reports non-2xx responses as tool errors', function () {
    Http::fake(['https://images.example.com/*' => Http::response('gone', 404)]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'responded with HTTP 404');

it('rejects bodies over the configured max_size', function () {
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB
    Http::fake(['https://images.example.com/*' => Http::response(str_repeat('x', 2048), 200)]);

    downloaderWithPublicDns()->download('https://images.example.com/big.png');
})->throws(ToolException::class, 'exceeds the 1 KB limit');

it('rejects empty bodies', function () {
    Http::fake(['https://images.example.com/*' => Http::response('', 200)]);

    downloaderWithPublicDns()->download('https://images.example.com/empty.png');
})->throws(ToolException::class, 'empty body');
