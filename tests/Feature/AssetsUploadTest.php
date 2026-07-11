<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetCreating;
use Statamic\Facades\AssetContainer;

it('uploads base64 content into the container root and reports liveness', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('"is_image":true')
        ->assertSee('"result":"uploaded — live"')
        ->assertSee('"cp_edit_url"');

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
    // CP parity: the upload path writes the asset's .meta.yaml too.
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeTrue();
});

it('uploads into a folder, creating it on demand', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
            'folder' => '/blog/2026/',
        ])
        ->assertOk()
        ->assertSee('"id":"images::blog/2026/hero.png"')
        ->assertSee('"folder":"blog/2026"');

    expect(Storage::disk('images')->exists('blog/2026/hero.png'))->toBeTrue();
});

it('requires exactly one of source_url and content_base64', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'filename' => 'a.png'])
        ->assertHasErrors(['pass exactly one of source_url or content_base64']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/a.png',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.png',
        ])
        ->assertHasErrors(['pass exactly one of source_url or content_base64']);
});

it('rejects invalid base64 and requires a filename with an extension', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => 'not base64!!!', 'filename' => 'a.png'])
        ->assertHasErrors(['content_base64 is not valid base64 — encode the raw file bytes']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x')])
        ->assertHasErrors(['pass filename — it could not be derived from the source_url']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x'), 'filename' => 'noextension'])
        ->assertHasErrors(["filename needs an extension, e.g. 'hero.jpg' — the container's rules and Statamic's file guards key off it"]);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x'), 'filename' => '../evil.png'])
        ->assertHasErrors(["filename must be a bare name like 'hero.jpg' — use folder for the destination path"]);
});

it('enforces the max_size cap on decoded base64', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(str_repeat('x', 2048)),
            'filename' => 'big.txt',
        ])
        ->assertHasErrors(['decoded file exceeds the 1 KB limit (statamic.mcp.uploads.max_size)']);
});

it("blocks files Statamic's AllowedFile guard forbids", function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('<?php evil();'),
            'filename' => 'shell.php',
        ])
        // The exact message comes from Statamic's validation translations —
        // assert our wrapper prefix, not the translated rule text.
        ->assertSee('upload validation failed');

    expect(Storage::disk('images')->exists('shell.php'))->toBeFalse();
});

it("applies the container's own validation rules like the CP does", function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    AssetContainer::findByHandle('images')->validationRules(['mimes:jpg,png'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('plain text'),
            'filename' => 'notes.txt',
        ])
        ->assertSee('upload validation failed');

    expect(Storage::disk('images')->exists('notes.txt'))->toBeFalse();
});

it('refuses to overwrite an existing path, naming the existing asset', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(["asset 'hero.png' already exists in container 'images' (id 'images::hero.png') — pick another filename, or delete it first if it should be replaced"]);
});

// NOTE: the planned "refuses containers with uploads disabled" test was
// dropped: Statamic 6.x removed the per-container allow_uploads toggle
// entirely (no method, not in AssetContainer::fileData()); the CP's only
// upload gate is the 'upload {handle} assets' permission (AssetPolicy::store),
// which is covered below.

it('reports an AssetCreating listener cancellation honestly', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Event::listen(AssetCreating::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['the upload was cancelled by a listener on this site — nothing was created']);

    expect(Storage::disk('images')->exists('hero.png'))->toBeFalse();
});

it('denies uploading without the upload permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser('view images assets'); // view is not upload

    Server::actingAs($user)
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.txt',
        ])
        ->assertHasErrors(["requires 'upload images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('blocks uploads in read-only mode', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    // The exact in-handler message is pinned by ReadOnlyModeTest's sweep.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.txt',
        ])
        ->assertHasErrors();

    expect(Storage::disk('images')->exists('a.txt'))->toBeFalse();
});
