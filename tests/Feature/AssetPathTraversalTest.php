<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsDelete;
use Danielgnh\StatamicMcp\Tools\AssetsGet;
use Danielgnh\StatamicMcp\Tools\AssetsUpdate;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    config(['statamic.mcp.deletes' => true]);

    $this->outside = dirname(Storage::disk('images')->path('')).'/secret.png';

    File::put($this->outside, 'outside the container');
});

afterEach(function () {
    File::delete($this->outside);
});

it('answers a path that climbs out of the container with a tool error, reporting nothing', function (string $tool, array $arguments) {
    Exceptions::fake();

    Server::actingAs(Fixtures::makeUser('view images assets', 'edit images assets', 'delete images assets'))
        ->tool($tool, ['container' => 'images', 'path' => '../secret.png', ...$arguments])
        ->assertHasErrors(["asset path '../secret.png' may not contain '../' — pass a path from assets_list"]);

    Exceptions::assertNothingReported();

    expect(File::get($this->outside))->toBe('outside the container')
        ->and(File::exists(dirname($this->outside).'/.meta/secret.png.yaml'))->toBeFalse();
})->with([
    'assets_get' => [AssetsGet::class, []],
    'assets_update' => [AssetsUpdate::class, ['data' => ['alt' => 'x']]],
    'assets_delete' => [AssetsDelete::class, []],
]);

it('answers an assets field value that climbs out of the container with a tool error, reporting nothing', function (array $data) {
    Fixtures::assetContainer('files');

    Collection::make('docs')->title('Docs')->routes('/docs/{slug}')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        'cover' => ['type' => 'assets', 'validate' => ['image']],
    ])->setHandle('doc')->setNamespace('collections.docs')->save();

    Exceptions::fake();

    Server::actingAs(Fixtures::makeUser('create docs entries'))
        ->tool(EntriesCreate::class, ['collection' => 'docs', 'data' => ['title' => 'Guide', ...$data]])
        ->assertHasErrors(["asset path '../secret.png' may not contain '../' — pass a path from assets_list"]);

    Exceptions::assertNothingReported();

    expect(Entry::query()->where('collection', 'docs')->count())->toBe(0);
})->with([
    'a path in a field with a container' => [['hero' => '../secret.png']],
    'an id with a backslash in a field with a container' => [['hero' => 'images::..\\secret.png']],
    'an id in a field without a container, checked by an image rule' => [['cover' => ['images::../secret.png']]],
]);
