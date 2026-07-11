<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Public-IP resolver so no test touches real DNS.
    app()->instance(SourceDownloader::class, new SourceDownloader(fn (string $host) => ['93.184.216.34']));
});

it('downloads from a url and derives the filename from it', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/photos/hero.png',
            'folder' => 'blog',
        ])
        ->assertOk()
        ->assertSee('"id":"images::blog/hero.png"')
        ->assertSee('"result":"uploaded — live"');

    expect(Storage::disk('images')->exists('blog/hero.png'))->toBeTrue();
});

it('lets an explicit filename override the url basename', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/download?id=42',
            'filename' => 'hero.png',
        ])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"');
});

it('asks for a filename when the url has no usable basename', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/',
        ])
        ->assertHasErrors(['pass filename — it could not be derived from the source_url']);
});

it('percent-decodes the derived basename', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/hero%20image.png',
        ])
        ->assertOk()
        // getSafeFilename turns the decoded space into a hyphen — the point
        // is that no literal '%20' survives into the stored path.
        ->assertDontSee('%20');

    expect(collect(Storage::disk('images')->allFiles())->contains(fn ($f) => str_contains($f, '%')))->toBeFalse();
});

it('surfaces SSRF refusals as tool errors and uploads nothing', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'http://169.254.169.254/latest/meta-data',
            'filename' => 'meta.txt',
        ])
        ->assertHasErrors(["source_url host '169.254.169.254' resolves to a private or reserved address — refusing to fetch"]);

    Http::assertNothingSent();
});

it('enforces max_size on downloads end-to-end', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB

    Http::fake(['https://images.example.com/*' => Http::response(str_repeat('x', 2048), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/big.png',
        ])
        ->assertHasErrors(['source_url file exceeds the 1 KB limit (statamic.mcp.uploads.max_size)']);
});
