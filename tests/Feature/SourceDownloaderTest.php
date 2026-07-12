<?php

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Facades\Http;

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
