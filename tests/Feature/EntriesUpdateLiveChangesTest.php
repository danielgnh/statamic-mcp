<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\UsesSerializingStacheStore;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

uses(UsesSerializingStacheStore::class);

it('leaves the index of an inheriting localization as Statamic alone would, on a cache store that hands out copies', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Old title'])->published(false))->save();
    $inheriting = tap($origin->makeLocalization('de')->published(true))->save();

    expect(Entry::query()->where('site', 'de')->where('title', 'Old title')->count())->toBe(1);

    Server::actingAs(Fixtures::makeUser('edit blog entries', 'access en site', 'access de site'))
        ->tool(EntriesUpdate::class, ['id' => $origin->id(), 'data' => ['title' => 'New title']])
        ->assertOk()
        ->assertSee('"live_changes":[{"id":"'.$inheriting->id().'","site":"de","inherits":["title"]}]');

    expect(Entry::query()->where('site', 'de')->where('title', 'New title')->count())->toBe(1)
        ->and(Entry::query()->where('site', 'de')->where('title', 'Old title')->count())->toBe(0);
});

it('reports the URL a live child page really has after its parent gets a new slug', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure('pages');

    $parent = Fixtures::page('about', 'About', published: false);
    $child = Fixtures::page('team', 'Team');
    $grandchild = Fixtures::page('founders', 'Founders');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $parent, 'children' => [['entry' => $child, 'children' => [['entry' => $grandchild]]]]]])->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $parent, 'data' => [], 'slug' => 'company'])
        ->assertOk()
        ->assertSee('{"id":"'.$child.'","site":"en","url":"/company/team","url_before":"/about/team"}')
        ->assertSee('{"id":"'.$grandchild.'","site":"en","url":"/company/team/founders","url_before":"/about/team/founders"}');

    expect(Entry::findByUri('/company/team', 'en')?->id())->toBe($child)
        ->and(Entry::findByUri('/company/team/founders', 'en')?->id())->toBe($grandchild)
        ->and(Entry::findByUri('/about/team', 'en'))->toBeNull();
});

it('says that a collection mounted on a draft page moves with its new slug', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    $journal = Fixtures::page('journal', 'Journal', published: false);

    Collection::findByHandle('blog')->mount($journal)->routes('{mount}/{slug}')->save();

    $post = tap(Entry::make()->collection('blog')->locale('en')->slug('post')->data(['title' => 'Post'])->published(true))->save();

    expect(Entry::find($post->id())->url())->toBe('/journal/post');

    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $journal, 'data' => [], 'slug' => 'stories'])
        ->assertOk()
        ->assertSee('"result":"saved as draft — not live, but live pages changed with it: see live_changes"')
        ->assertSee('"live_changes":[{"mounted_collection":true,"url":"/stories","url_before":"/journal"}]');

    expect(Entry::findByUri('/stories/post', 'en')?->id())->toBe($post->id());
});

it('says which live child pages move when a field the route uses changes on their parent', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure('pages');

    Collection::findByHandle('pages')->routes('{parent_uri}/{section}/{slug}')->save();
    Blueprint::find('collections.pages.page')->ensureField('section', ['type' => 'text'])->save();

    $parent = tap(Entry::make()->collection('pages')->locale('en')->slug('about')->data(['title' => 'About', 'section' => 'a'])->published(false))->save()->id();
    $child = tap(Entry::make()->collection('pages')->locale('en')->slug('team')->data(['title' => 'Team', 'section' => 'b'])->published(true))->save()->id();

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $parent, 'children' => [['entry' => $child]]]])->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $parent, 'data' => ['section' => 'z']])
        ->assertOk()
        ->assertSee('"live_changes":[{"id":"'.$child.'","site":"en","url":"/z/about/b/team","url_before":"/a/about/b/team"}]');

    expect(Entry::findByUri('/z/about/b/team', 'en')?->id())->toBe($child);
});

it('lists at most 50 live changes and counts the rest', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure('pages');

    $parent = Fixtures::page('about', 'About', published: false);
    $children = collect(range(1, 53))->map(fn (int $n) => ['entry' => Fixtures::page("child-{$n}", "Child {$n}")])->all();

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $parent, 'children' => $children]])->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $parent, 'data' => [], 'slug' => 'company'])
        ->assertOk()
        ->assertSee('"live_changes_not_listed":3');
});
