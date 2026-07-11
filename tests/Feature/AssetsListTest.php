<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsList;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;

it('lists assets with summary columns', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
    Storage::disk('images')->put('notes.txt', 'plain text');

    Asset::find('images::hero.png')->data(['alt' => 'A hero image'])->save();

    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images'])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('"path":"hero.png"')
        ->assertSee('"basename":"hero.png"')
        ->assertSee('"is_image":true')
        ->assertSee('"alt":"A hero image"')
        ->assertSee('"url":"/assets/images/hero.png"')
        ->assertSee('"id":"images::notes.txt"')
        ->assertSee('"is_image":false')
        ->assertSee('"folder":null')
        ->assertSee('"dimensions":null')
        ->assertSee('"total":2');
});

it('paginates ordered by path and reports the next page, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    // Saved out of order on purpose — the listing must sort by path.
    Storage::disk('images')->put('c.txt', 'c');
    Storage::disk('images')->put('a.txt', 'a');
    Storage::disk('images')->put('b.txt', 'b');

    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"next_page":2')
        ->assertSee('"path":"a.txt"')
        ->assertSee('"path":"b.txt"')
        ->assertDontSee('"path":"c.txt"');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"path":"c.txt"')
        ->assertDontSee('"path":"a.txt"')
        ->assertSee('"next_page":null');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('filters to a folder subtree', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('root.txt', 'r');
    Storage::disk('images')->put('blog/hero.png', Fixtures::tinyPng());
    Storage::disk('images')->put('blog/2026/nested.png', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeUser('view images assets'))
        ->tool(AssetsList::class, ['container' => 'images', 'folder' => '/blog/'])
        ->assertOk()
        ->assertSee('"path":"blog/hero.png"')
        ->assertSee('"path":"blog/2026/nested.png"')
        ->assertSee('"folder":"blog"')
        ->assertDontSee('"path":"root.txt"')
        ->assertSee('"total":2');
});

it('treats underscores in folder names literally, not as wildcards', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('my_assets/in.txt', 'in');
    Storage::disk('images')->put('myxassets/out.txt', 'out');

    Server::actingAs(Fixtures::makeUser('view images assets'))
        ->tool(AssetsList::class, ['container' => 'images', 'folder' => 'my_assets'])
        ->assertOk()
        ->assertSee('"path":"my_assets/in.txt"')
        ->assertDontSee('"path":"myxassets/out.txt"')
        ->assertSee('"total":1');
});

it('rejects folder traversal', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsList::class, ['container' => 'images', 'folder' => '../secrets'])
        ->assertHasErrors(["folder may not contain '..' or backslashes — pass a path like 'blog/2026'"]);
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images'])
        ->assertHasErrors(["requires 'view images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed container as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('secrets');

    config(['statamic.mcp.resources.asset_containers' => ['images']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsList::class, ['container' => 'secrets'])
        ->assertHasErrors(["asset container 'secrets' not found — available: images"]);
});
