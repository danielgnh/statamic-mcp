<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesList;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

it('lists entries in an exposed collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()
        ->collection('blog')
        ->slug('hello-world')
        ->data(['title' => 'Hello World'])
        ->published(true)
        ->save();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('hello-world');
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('returns summary columns for each entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()
        ->collection('blog')
        ->slug('summary-post')
        ->data(['title' => 'Summary Post'])
        ->published(true)
        ->save();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('"slug":"summary-post"')
        ->assertSee('"title":"Summary Post"')
        ->assertSee('"status":"published"')
        ->assertSee('"url"')
        ->assertSee('"updated_at"');
});

it('filters by status via whereStatus', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()->collection('blog')->slug('live-post')->data(['title' => 'Live'])->published(true)->save();
    Entry::make()->collection('blog')->slug('draft-post')->data(['title' => 'Draft'])->published(false)->save();

    // total:1 proves the published entry was excluded (no negative assertion needed)
    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesList::class, ['collection' => 'blog', 'status' => 'draft'])
        ->assertOk()
        ->assertSee('draft-post')
        ->assertSee('"total":1');
});

it('paginates with totals and a next-page hint, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    foreach (range(1, 3) as $i) {
        Entry::make()
            ->collection('blog')
            ->slug("post-{$i}")
            ->data(['title' => "Post {$i}"])
            ->published(true)
            ->save();
    }

    $user = Fixtures::makeUser('view blog entries');

    // undated collection → alphabetical by title: Post 1, Post 2 | Post 3
    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"total_pages":2')
        ->assertSee('"next_page":2')
        ->assertSee('post-1')
        ->assertSee('post-2')
        ->assertDontSee('post-3');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"next_page":null')
        ->assertSee('post-3')
        ->assertDontSee('post-1')
        ->assertDontSee('post-2');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('orders dated collections newest-first with a deterministic tiebreaker', function () {
    Fixtures::site();

    tap(
        Collection::make('news')
            ->title('News')
            ->dated(true)
            ->sites(['en'])
            ->routes('/news/{slug}')
    )->save();

    Entry::make()->collection('news')->slug('oldest-story')->date('2026-01-01')->data(['title' => 'Oldest'])->published(true)->save();
    Entry::make()->collection('news')->slug('newest-story')->date('2026-03-01')->data(['title' => 'Newest'])->published(true)->save();
    Entry::make()->collection('news')->slug('middle-story')->date('2026-02-01')->data(['title' => 'Middle'])->published(true)->save();

    $user = Fixtures::makeUser('view news entries');

    // Stache traversal order is date-ASCENDING — page 1 must be the newest entry
    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'news', 'limit' => 1, 'page' => 1])
        ->assertOk()
        ->assertSee('newest-story')
        ->assertSee('"date":"2026-03-01')
        ->assertDontSee('middle-story')
        ->assertDontSee('oldest-story');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'news', 'limit' => 1, 'page' => 3])
        ->assertOk()
        ->assertSee('oldest-story')
        ->assertDontSee('newest-story');
});

it('lists only entries of the requested site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()->collection('blog')->locale('en')->slug('english-post')->data(['title' => 'English'])->published(true)->save();
    Entry::make()->collection('blog')->locale('de')->slug('deutscher-beitrag')->data(['title' => 'Deutsch'])->published(true)->save();

    $user = Fixtures::makeUser('view blog entries', 'access de site');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'de'])
        ->assertOk()
        ->assertSee('deutscher-beitrag')
        ->assertSee('"total":1')
        ->assertDontSee('english-post');

    // default (en) listing must exclude the de entry
    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('english-post')
        ->assertSee('"total":1')
        ->assertDontSee('deutscher-beitrag');
});

it('treats an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.resources.collections' => ['pages']]); // 'blog' exists but is not exposed

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(["collection 'blog' not found — available: (none exposed)"]);
});

it('rejects an unknown site naming the available ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'fr'])
        ->assertHasErrors(["site 'fr' not found — available: en"]);
});

it("requires 'access {site} site' for non-default sites", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"total":0');
});
