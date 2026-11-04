<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsList;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\ImageDecoderException;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Statamic\Events\AssetCreating;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetUploaded;
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
        ->assertSee('"path":"hero.png"')
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
        ->assertSee('"path":"blog/2026/hero.png"');

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

it('reports an upload whose filename holds braces as uploaded', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'wei{rd}.png',
            'folder' => 'blog',
        ])
        ->assertOk()
        ->assertSee('"path":"blog/wei{rd}.png"')
        ->assertSee('"result":"uploaded — live"')
        ->assertSee('"cp_edit_url":null');

    expect(Storage::disk('images')->exists('blog/wei{rd}.png'))->toBeTrue();
});

function imagesDiskThatRefusesWrites(): void
{
    $root = Storage::disk('images')->path('');

    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public function write(string $path, string $contents, Config $config): void
        {
            throw UnableToWriteFile::atLocation($path, 'read-only');
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            throw UnableToWriteFile::atLocation($path, 'read-only');
        }
    };

    Storage::set('images', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root]));
}

it('never reports an upload as live when the disk refused the write', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Queue::fake();
    imagesDiskThatRefusesWrites();

    $user = Fixtures::makeUser('upload images assets', 'view images assets');

    Server::actingAs($user)
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['the disk did not store'])
        ->assertDontSee('uploaded — live');

    expect(Storage::disk('images')->allFiles())->toBe([]);

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images'])
        ->assertOk()
        ->assertSee('"total":0');
});

it('tells the caller nothing was stored when a listener fails after the disk refused the write', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();
    imagesDiskThatRefusesWrites();

    Event::listen(AssetUploaded::class, fn () => throw UnableToReadFile::fromLocation('hero.png'));

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['the disk did not store']);

    expect(Storage::disk('images')->allFiles())->toBe([]);
});

it('refuses a filename too long for its .yaml sidecar to fit the 255 bytes filesystems allow', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Queue::fake();

    $upload = fn (string $name) => Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => $name,
        ]);

    $upload(str_repeat('a', 247).'.png')
        ->assertHasErrors(['filename is longer than 250 bytes']);

    expect(Storage::disk('images')->allFiles())->toBe([]);

    $upload(str_repeat('a', 246).'.png')
        ->assertOk();

    expect(Storage::disk('images')->exists(str_repeat('a', 246).'.png'))->toBeTrue()
        ->and(Storage::disk('images')->exists('.meta/'.str_repeat('a', 246).'.png.yaml'))->toBeTrue();
});

it('names the stored path and what failed when the inline preset listener cannot read the file', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();

    expect(config('queue.default'))->toBe('sync');

    $name = 'junk-'.bin2hex(random_bytes(4)).'.png';

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('this is not a png'),
            'filename' => $name,
            'folder' => 'blog',
        ])
        ->assertOk()
        ->assertSee('"path":"blog/'.$name.'"')
        ->assertSee('"result":"uploaded — live"')
        ->assertSee('"not_stored":["image presets —');

    Exceptions::assertReported(ImageDecoderException::class);

    expect(Storage::disk('images')->exists("blog/{$name}"))->toBeTrue();
});

it('names the stored path when the body is an HTML page under an image name', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();

    $name = 'page-'.bin2hex(random_bytes(4)).'.png';

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('<html><body>Access denied</body></html>'),
            'filename' => $name,
        ])
        ->assertOk()
        ->assertSee('"path":"'.$name.'"')
        ->assertSee('"not_stored":["image presets —');

    Exceptions::assertReported(UnableToReadFile::class);

    expect(Storage::disk('images')->exists($name))->toBeTrue();
});

it('refuses a folder with a control character as a tool error, nothing reported', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
            'folder' => "blog\n2026",
        ])
        ->assertHasErrors(['folder may not contain control characters']);

    Exceptions::assertNothingReported();

    expect(Storage::disk('images')->allFiles())->toBe([]);
});

it('stores a filename with a control character under a clean name, nothing reported', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();
    Queue::fake();

    $response = Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => "he\nro.png",
        ]);

    Exceptions::assertNothingReported();

    $response->assertOk();

    expect(array_filter(Storage::disk('images')->allFiles(), fn (string $path) => ! str_contains($path, '.meta')))->toHaveCount(1);
});

it('refuses a .meta folder, because no later call could reach a file stored there', function (string $folder) {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hidden.png',
            'folder' => $folder,
        ])
        ->assertHasErrors(["folder may not be Statamic's .meta directory"]);

    expect(Storage::disk('images')->allFiles())->toBe([]);
})->with(['.meta', 'blog/.meta', '.meta/sub', '.META', 'blog/.Meta']);

it('does not call a failed asset save a stored upload', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['app.debug' => false]);
    Exceptions::fake();

    Event::listen(AssetUploaded::class, fn () => throw new RuntimeException('the asset database is down'));

    $response = Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ]);

    $response->assertHasErrors(['An internal server error occurred.'])
        ->assertDontSee('uploaded — live')
        ->assertDontSee('not a readable image');

    Exceptions::assertReported(RuntimeException::class);
});

it('lets a validation error from a listener reach the agent', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Exceptions::fake();

    Event::listen(AssetCreating::class, fn () => throw ValidationException::withMessages(['file' => 'This site refuses that image.']));

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['This site refuses that image.'])
        ->assertDontSee('the disk did not store');

    expect(Storage::disk('images')->allFiles())->toBe([]);
});

it('undoes a refused upload quietly, because AssetDeleted would strip every reference to that path', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Queue::fake();
    Event::fake([AssetDeleted::class]);
    imagesDiskThatRefusesWrites();

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['the disk did not store']);

    Event::assertNotDispatched(AssetDeleted::class);
});
