<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsGet;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;

it('returns full asset detail including raw blueprint data', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('blog/hero.png', Fixtures::tinyPng());
    Asset::find('images::blog/hero.png')->data(['alt' => 'A hero image'])->save();

    Server::actingAs(Fixtures::makeUser('view images assets'))
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'blog/hero.png'])
        ->assertOk()
        ->assertSee('"id":"images::blog/hero.png"')
        ->assertSee('"folder":"blog"')
        ->assertSee('"is_image":true')
        ->assertSee('"data":{"alt":"A hero image"}')
        ->assertSee('"mime_type":"image/png"')
        ->assertSee('"last_modified"')
        ->assertSee('"cp_edit_url"');
});

it('normalizes a leading slash on path', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => '/hero.png'])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"');
});

it('reports a missing path with a pointer to assets_list', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'nope.png'])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});

it('denies reading without the view permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(["requires 'view images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed container as missing', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('secrets');
    Storage::disk('secrets')->put('x.txt', 'x');

    config(['statamic.mcp.resources.asset_containers' => ['images']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsGet::class, ['container' => 'secrets', 'path' => 'x.txt'])
        ->assertHasErrors(["asset container 'secrets' not found — available: images"]);
});
