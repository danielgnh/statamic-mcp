<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsDelete;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Statamic\Events\AssetDeleting;
use Statamic\Facades\Asset;

beforeEach(function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
    config(['statamic.mcp.deletes' => true]);
});

it('deletes the file and its metadata', function () {
    // Force a .meta.yaml onto disk so the test proves both files go.
    Asset::find('images::hero.png')->data(['alt' => 'x'])->save();
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeTrue();

    Server::actingAs(Fixtures::makeUser('delete images assets'))
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertOk()
        ->assertSee('"deleted":true')
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('asset permanently deleted');

    expect(Storage::disk('images')->exists('hero.png'))->toBeFalse();
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeFalse();
});

it('is blocked when deletes are disabled', function () {
    config(['statamic.mcp.deletes' => false]);

    // Either gate rejects — the registration gate or the in-handler re-check;
    // both are errors. The exact message is pinned by ReadOnlyModeTest's sweep.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors();

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('is blocked by read_only even with deletes enabled', function () {
    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors();

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('re-checks the deletes gate inside the handler, not just at registration', function () {
    config(['statamic.mcp.deletes' => false]);

    // Call handle() directly, bypassing tools/list — exactly what a client
    // with a stale tool cache does after the server flipped deletes off.
    // The harness enforces shouldRegister(), so only a direct call can pin
    // the in-handler re-check (spec §6 layer 1).
    $response = (new AssetsDelete)->handle(new Request(['container' => 'images', 'path' => 'hero.png']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('statamic.mcp.deletes');

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('reports an AssetDeleting listener cancellation honestly', function () {
    Event::listen(AssetDeleting::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(['the delete was cancelled by a listener — the asset was not deleted']);

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('denies deleting without the delete permission', function () {
    $user = Fixtures::makeUser('edit images assets');

    Server::actingAs($user)
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(["requires 'delete images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('reports a missing path with a pointer to assets_list', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'nope.png'])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});
