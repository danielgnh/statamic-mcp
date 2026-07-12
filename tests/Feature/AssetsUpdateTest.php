<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpdate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetSaving;
use Statamic\Facades\Asset;

beforeEach(function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
});

it('merges raw metadata and reports it live', function () {
    Asset::find('images::hero.png')->data(['alt' => 'Old alt'])->save();

    Server::actingAs(Fixtures::makeUser('edit images assets'))
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'A better alt text'],
        ])
        ->assertOk()
        ->assertSee('"data":{"alt":"A better alt text"}')
        ->assertSee('"result":"updated — live"')
        ->assertSee('"cp_edit_url"');

    expect(Asset::find('images::hero.png')->data()->get('alt'))->toBe('A better alt text');
});

it('rejects unknown fields naming valid handles', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['allt' => 'typo'],
        ])
        ->assertHasErrors(["unknown field allt — valid handles: alt — did you mean 'alt' instead of 'allt'?"]);
});

it('accepts focus as a pass-through key (CP focal point round-trip)', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'With focus', 'focus' => '50-50-1'],
        ])
        ->assertOk()
        ->assertSee('"focus":"50-50-1"');

    expect(Asset::find('images::hero.png')->data()->get('focus'))->toBe('50-50-1');
});

it('is a no-op when the merged data equals the current data', function () {
    Asset::find('images::hero.png')->data(['alt' => 'Same'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'Same'],
        ])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');
});

it('reports an AssetSaving listener cancellation honestly', function () {
    Event::listen(AssetSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors(['the save was cancelled by a listener — the asset metadata was not updated']);
});

it('denies updating without the edit permission', function () {
    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors(["requires 'edit images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('blocks updates in read-only mode', function () {
    config(['statamic.mcp.read_only' => true]);

    // Either gate rejects — the registration gate or the in-handler
    // re-check; both are errors. The exact message is pinned by ReadOnlyModeTest's sweep.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors();

    expect(Asset::find('images::hero.png')->data()->get('alt'))->toBeNull();
});

it('reports a missing path with a pointer to assets_list', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'nope.png',
            'data' => ['alt' => 'x'],
        ])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});
