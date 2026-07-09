<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;

it('builds content fixtures in the sandboxed stache', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::settings();

    expect(Collection::handles()->all())->toContain('blog')
        ->and(Taxonomy::handles()->all())->toContain('tags')
        ->and(GlobalSet::findByHandle('settings'))->not->toBeNull();
});

it('creates users with access mcp plus the given permissions', function () {
    Fixtures::site();

    $user = Fixtures::makeUser('view blog entries');

    expect($user->hasPermission('access mcp'))->toBeTrue()
        ->and($user->hasPermission('view blog entries'))->toBeTrue()
        ->and($user->hasPermission('edit blog entries'))->toBeFalse()
        ->and($user->isSuper())->toBeFalse();
});

it('creates super users', function () {
    Fixtures::site();

    expect(Fixtures::makeSuper()->isSuper())->toBeTrue();
});
