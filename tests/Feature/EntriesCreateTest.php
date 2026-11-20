<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Statamic\Events\EntryCreating;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

function makeDatedEventsCollection(): void
{
    tap(
        Collection::make('events')
            ->title('Events')
            ->dated(true)
            ->sites(['en'])
            ->routes('/events/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('event')->setNamespace('collections.events')->save();
}

it('creates a draft by default with a slug generated from the title', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('create blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My First Post']])
        ->assertOk()
        ->assertSee('"slug":"my-first-post"')
        ->assertSee('"status":"draft"')
        ->assertSee('saved as draft — not live')
        ->assertSee('"cp_edit_url"');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'my-first-post')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->get('title'))->toBe('My First Post')
        ->and($entry->get('updated_by'))->toBe($user->id()); // CP parity: creates carry updated_by/updated_at
});

it('rejects published even for a user who could publish', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // A client with a stale tool cache may still send the old parameter.
    // Silently saving a draft the agent believes is live would be worse
    // than an error, so it is refused outright.
    foreach ([true, false] as $published) {
        Server::actingAs(Fixtures::makeUser('create blog entries', 'publish blog entries'))
            ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Live Post'], 'published' => $published])
            ->assertHasErrors(['published is not accepted by entries_create — publish state changes only through entries_publish and entries_unpublish']);
    }

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('requires date for dated collections', function () {
    Fixtures::site();
    makeDatedEventsCollection();

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party']])
        ->assertHasErrors(["collection 'events' is dated — pass date (e.g. 2026-07-09 or 2026-07-09T15:30:00+02:00)"]);

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party'], 'date' => '2026-08-01'])
        ->assertOk()
        ->assertSee('"slug":"launch-party"');
});

it('rejects date inside data on a dated collection', function () {
    Fixtures::site();
    makeDatedEventsCollection();

    // The injected 'date' blueprint field would otherwise let a data key
    // silently shadow the top-level date param.
    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party', 'date' => '2026-08-01']])
        ->assertHasErrors(['pass date as a top-level parameter, not inside data']);
});

it('rejects date on a non-dated collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi'], 'date' => '2026-08-01'])
        ->assertHasErrors(["collection 'blog' is not dated — omit date"]);
});

it('refuses a slug whose URL another entry of the collection has, naming that entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $existing = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('view blog entries', 'create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello World'], 'slug' => 'hello-world'])
        ->assertHasErrors(["URL '/blog/hello-world' already belongs to entry '{$existing->id()}' in collection 'blog' — pick another slug"]);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(1);
});

it('refuses a URL an entry of an unexposed collection has without naming that entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    Collection::findByHandle('pages')->routes('/blog/{slug}')->save();

    $page = Fixtures::page('hello', 'Hello', published: false);

    config(['statamic.mcp.resources.collections' => ['blog']]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'create blog entries', 'view pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello'], 'slug' => 'hello'])
        ->assertHasErrors(["URL '/blog/hello' is already taken — pick another slug"])
        ->assertDontSee($page)
        ->assertDontSee('pages');

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('names the entry whose URL a create would take only to a caller who can view it', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    Collection::findByHandle('pages')->routes('/blog/{slug}')->save();

    $page = Fixtures::page('hello', 'Hello', published: false);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello'], 'slug' => 'hello'])
        ->assertHasErrors(["URL '/blog/hello' is already taken — pick another slug"])
        ->assertDontSee($page)
        ->assertDontSee('pages');

    Server::actingAs(Fixtures::makeUser('view blog entries', 'create blog entries', 'view pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello'], 'slug' => 'hello'])
        ->assertHasErrors(["URL '/blog/hello' already belongs to entry '{$page}' in collection 'pages' — pick another slug"]);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('allows a slug another entry has in a collection without a route, as the CP does', function () {
    Fixtures::site();

    tap(Collection::make('snippets')->title('Snippets')->sites(['en']))->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('snippet')->setNamespace('collections.snippets')->save();

    $existing = tap(Entry::make()->collection('snippets')->slug('footer')->data(['title' => 'Footer']))->save();

    Server::actingAs(Fixtures::makeUser('create snippets entries'))
        ->tool(EntriesCreate::class, ['collection' => 'snippets', 'data' => ['title' => 'Footer'], 'slug' => 'footer'])
        ->assertOk()
        ->assertSee('"slug":"footer"');

    // Statamic writes the second one to footer.1.md: neither overwrites the other.
    Stache::clear();

    $entries = Entry::query()->where('collection', 'snippets')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->map->slug()->all())->toBe(['footer', 'footer'])
        ->and($entries->map->id()->contains($existing->id()))->toBeTrue();
});

it('rejects unknown data keys with valid handles and a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi', 'hero_imge' => 'x.jpg']])
        ->assertHasErrors(["unknown field hero_imge — valid handles: content, hero_image, title, topic — did you mean 'hero_image' instead of 'hero_imge'?"]);
});

it('creates entries on a blueprint that marks slug as required', function () {
    Fixtures::site();
    Fixtures::pages();

    // The resolved slug must reach blueprint validation — slug is barred from
    // data and lives as a top-level param, so without the merge a required
    // slug field would be unsatisfiable (catch-22).
    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About Us']])
        ->assertOk()
        ->assertSee('"slug":"about-us"');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Contact'], 'slug' => 'get-in-touch'])
        ->assertOk()
        ->assertSee('"slug":"get-in-touch"');
});

it('rejects slug inside data with a targeted message', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi', 'slug' => 'hi']])
        ->assertHasErrors(['pass slug as a top-level parameter, not inside data']);
});

it('returns field-level validation errors from the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // title is required by the article blueprint (slug passed explicitly —
    // its resolution runs first and would otherwise mask the field errors)
    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['hero_image' => 'x.jpg'], 'slug' => 'post'])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('denies creating without the create permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(["requires 'create blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('refuses to create when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors();

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('normalizes the slug the way Statamic will persist it before checking the URL', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $existing = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello'])->published(true)
    )->save();

    // Entry::save() re-normalizes 'Hello World' to 'hello-world' — a check on
    // the raw value would miss this and silently produce two entries, one URL.
    Server::actingAs(Fixtures::makeUser('view blog entries', 'create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Fresh'], 'slug' => 'Hello World'])
        ->assertHasErrors(["URL '/blog/hello-world' already belongs to entry '{$existing->id()}' in collection 'blog' — pick another slug"]);
});

it('generates the slug with the target site language (CP parity)', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries', 'access de site'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Über Uns'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"slug":"ueber-uns"'); // de transliterates Ü → Ue; en would give 'uber-uns'
});

it('rejects a title that normalizes to an empty slug', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => '🎉🎉🎉']])
        ->assertHasErrors(['could not derive a slug from the title — pass slug explicitly']);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('creates an entry without a slug on a collection with slugs turned off, as the CP does', function () {
    Fixtures::site();
    Fixtures::faqs();

    Server::actingAs(Fixtures::makeUser('create faqs entries'))
        ->tool(EntriesCreate::class, ['collection' => 'faqs', 'data' => ['title' => 'How long does delivery take?']])
        ->assertOk()
        ->assertSee('"slug":null');

    // Statamic names the file of an entry without a slug by its id.
    Stache::clear();

    $entry = Entry::query()->where('collection', 'faqs')->first();

    expect($entry->slug())->toBeNull()
        ->and($entry->path())->toEndWith("/faqs/{$entry->id()}.md");
});

it('refuses a slug on a collection with slugs turned off', function () {
    Fixtures::site();
    Fixtures::faqs();

    Server::actingAs(Fixtures::makeUser('create faqs entries'))
        ->tool(EntriesCreate::class, ['collection' => 'faqs', 'data' => ['title' => 'Delivery'], 'slug' => 'delivery'])
        ->assertHasErrors(["collection 'faqs' has slugs turned off, so its entries have none — omit slug"]);

    expect(Entry::query()->where('collection', 'faqs')->count())->toBe(0);
});

it('makes a slug when the blueprint has a slug field of its own, even with slugs turned off', function () {
    Fixtures::site();
    Fixtures::faqs();

    // The CP goes by the blueprint: turning slugs off only stops Statamic
    // from adding the field.
    Blueprint::find('collections.faqs.faq')->ensureField('slug', ['type' => 'slug'])->save();

    Server::actingAs(Fixtures::makeUser('create faqs entries'))
        ->tool(EntriesCreate::class, ['collection' => 'faqs', 'data' => ['title' => 'Delivery Times']])
        ->assertOk()
        ->assertSee('"slug":"delivery-times"');
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // Approval-workflow addons cancel saves by returning false from
    // EntryCreating/EntrySaving; Entry::save() then returns false.
    Event::listen(EntryCreating::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(['the save was cancelled by a listener on this site — nothing was created']);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('rejects a site the collection is not configured for', function () {
    Fixtures::multisite();

    tap(
        Collection::make('docs')->title('Docs')->sites(['en'])->routes('/docs/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('doc')->setNamespace('collections.docs')->save();

    Server::actingAs(Fixtures::makeUser('create docs entries', 'access de site'))
        ->tool(EntriesCreate::class, ['collection' => 'docs', 'data' => ['title' => 'Hi'], 'site' => 'de'])
        ->assertHasErrors(["collection 'docs' is not available in site 'de' — available sites: en"]);
});

it('applies rule placeholder replacements so unique_entry_value scopes to the collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    tap(
        Collection::make('products')->title('Products')->sites(['en'])->routes('/products/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'sku' => ['type' => 'text', 'validate' => ['new \Statamic\Rules\UniqueEntryValue({collection}, {id}, {site})']],
    ])->setHandle('product')->setNamespace('collections.products')->save();

    // Same sku in ANOTHER collection: an unreplaced {collection} placeholder
    // becomes null and the rule queries ALL collections — this entry must not
    // block a products create once replacements are wired.
    tap(
        Entry::make()->collection('blog')->slug('other')->data(['title' => 'Other', 'sku' => 'ABC-1'])->published(true)
    )->save();

    tap(
        Entry::make()->collection('products')->slug('first')->data(['title' => 'First', 'sku' => 'ABC-2'])->published(true)
    )->save();

    // Duplicate within products → field-level validation error.
    Server::actingAs(Fixtures::makeUser('create products entries'))
        ->tool(EntriesCreate::class, ['collection' => 'products', 'data' => ['title' => 'Dupe', 'sku' => 'ABC-2']])
        ->assertHasErrors()
        ->assertSee('validation failed');

    // Same sku existing only in blog → scoped rule passes.
    Server::actingAs(Fixtures::makeUser('create products entries'))
        ->tool(EntriesCreate::class, ['collection' => 'products', 'data' => ['title' => 'Fine', 'sku' => 'ABC-1']])
        ->assertOk();
});

it('rejects reserved keys in data even when the blueprint defines them', function () {
    Fixtures::site();

    tap(
        Collection::make('pages')->title('Pages')->sites(['en'])->routes('/pages/{slug}')
    )->save();

    // A 'published' blueprint field would otherwise let a create-only user
    // persist publish state through data (data keys shadow front matter).
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'published' => ['type' => 'toggle'],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Hi', 'published' => true]])
        ->assertHasErrors(['field published is reserved — never writable via data']);
});

it('stores sets and single-file assets the way the CP does', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => [
            'title' => 'Home',
            'hero' => ['hero.jpg'],
            'page_builder' => [['type' => 'section_hero', 'heading' => 'Welcome', 'image' => ['hero.jpg']]],
        ]])
        ->assertOk();

    $entry = Entry::query()->where('collection', 'landing')->where('slug', 'home')->first();
    $set = $entry->get('page_builder')[0];

    expect($entry->get('hero'))->toBe('hero.jpg')
        ->and($set)->toMatchArray(['type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg'])
        ->and($set['id'])->toBeString()->not->toBeEmpty();
});

it('stores a single-item relationship as a plain id', function (mixed $topic) {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Post', 'topic' => $topic]])
        ->assertOk();

    expect(Entry::query()->where('collection', 'blog')->where('slug', 'post')->first()->get('topic'))->toBe('laravel');
})->with([
    'a plain id' => 'laravel',
    'a one-item list' => [['laravel']],
]);

it('stores dates in the field save format', function (string $starts) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Launch', 'starts' => $starts]])
        ->assertOk();

    expect(Entry::query()->where('collection', 'landing')->where('slug', 'launch')->first()->get('starts'))
        ->toBe('2026-01-15 09:30');
})->with([
    'the stored format' => '2026-01-15 09:30',
    'an ISO-8601 instant' => '2026-01-15T09:30:00.000Z',
]);

it('converts HTML sent to a Bard field into ProseMirror nodes', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', 'body' => '<p>Hello</p>']])
        ->assertOk();

    expect(Entry::query()->where('collection', 'landing')->where('slug', 'home')->first()->get('body')[0])
        ->toMatchArray(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]);
});

it('rejects a set type the field does not define', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => [
            'title' => 'Home',
            'page_builder' => [['type' => 'section_heros', 'heading' => 'Hi']],
        ]])
        ->assertHasErrors(["unknown set type 'section_heros' in page_builder.0 — valid set types: section_hero, section_text_block — did you mean 'section_hero'?"]);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
});

it('rejects malformed nested data before anything is saved', function (array $data, string $error) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', ...$data]])
        ->assertHasErrors([$error]);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
})->with([
    'a typo inside a set' => [
        ['page_builder' => [['type' => 'section_hero', 'heading' => 'Hi', 'headng' => 'Hi']]],
        "unknown field headng in page_builder.0 — valid handles: heading, image — did you mean 'heading' instead of 'headng'?",
    ],
    'a set without a type' => [
        ['page_builder' => [['heading' => 'Hi']]],
        'page_builder.0 must be a set object with a type — valid set types: section_hero, section_text_block',
    ],
    'a set that is not an object' => [
        ['page_builder' => ['section_hero']],
        'page_builder.0 must be a set object with a type',
    ],
    'sets that are not a list' => [
        ['page_builder' => 'section_hero'],
        'page_builder must be a list of sets',
    ],
    'an unknown Bard set' => [
        ['body' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Hi']]]]],
        "unknown set type 'quote' in body.0.attrs.values — valid set types: callout",
    ],
    'a Bard node without a type' => [
        ['body' => [['text' => 'Hi']]],
        'body.0 must be a ProseMirror node object with a type',
    ],
    'a typo inside a grid row' => [
        ['facts' => [['lable' => 'Rooms']]],
        "unknown field lable in facts.0 — valid handles: label — did you mean 'label' instead of 'lable'?",
    ],
    'a typo inside a group' => [
        ['seo' => ['meta_titel' => 'Home']],
        "unknown field meta_titel in seo — valid handles: meta_title — did you mean 'meta_title' instead of 'meta_titel'?",
    ],
]);

it('rejects an asset reference that does not resolve in the field container', function (array $data, string $error) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', ...$data]])
        ->assertHasErrors([$error]);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
})->with([
    'a missing path' => [
        ['hero' => 'missing.jpg'],
        "asset 'missing.jpg' not found in container 'images' (hero) — pass a path from assets_list or assets_upload",
    ],
    'a url' => [
        ['hero' => '/assets/images/hero.jpg'],
        "asset '/assets/images/hero.jpg' not found in container 'images' (hero)",
    ],
    "another container's id" => [
        ['hero' => 'files::hero.jpg'],
        "asset 'files::hero.jpg' not found in container 'images' (hero)",
    ],
    'a missing path inside a set' => [
        ['page_builder' => [['type' => 'section_hero', 'heading' => 'Hi', 'image' => ['missing.jpg']]]],
        "asset 'missing.jpg' not found in container 'images' (page_builder.0.image)",
    ],
]);

it('refuses a file of an unexposed container the same whether it exists or not, naming no container', function (array $data, string $error) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => []]]);

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', ...$data]])
        ->assertHasErrors([$error])
        ->assertDontSee('images');

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
})->with([
    'an existing file' => [
        ['hero' => 'hero.jpg'],
        'hero links a container not exposed to MCP — leave it out of data',
    ],
    'a missing file' => [
        ['hero' => 'missing.jpg'],
        'hero links a container not exposed to MCP — leave it out of data',
    ],
    'an existing file inside a set' => [
        ['page_builder' => [['type' => 'section_hero', 'heading' => 'Hi', 'image' => ['hero.jpg']]]],
        'page_builder.0.image links a container not exposed to MCP — leave it out of data',
    ],
]);

it('answers a field whose container does not exist like an unexposed one when resources lists containers', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Blueprint::find('collections.landing.landing_page')
        ->ensureField('brochure', ['type' => 'assets', 'container' => 'archive', 'max_files' => 1])
        ->save();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => ['images']]]);

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', 'brochure' => 'guide.pdf']])
        ->assertHasErrors(['brochure links a container not exposed to MCP — leave it out of data']);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
});

it('names the field when Statamic cannot process its value', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Launch', 'starts' => 'TBD']])
        ->assertHasErrors(["field starts has a value its fieldtype (date) can't process"]);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
});

it('makes the acting user the author, so they can edit what they create', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $user = Fixtures::makeUser('create blog entries', 'edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My Post']])
        ->assertOk();

    $entry = Entry::query()->where('collection', 'blog')->first();

    expect($entry->authors()->all())->toBe([$user->id()]);

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'My Post, edited']])
        ->assertOk();
});

it('stores the default author the way the CP does', function (?int $maxItems, bool $plainId) {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors(maxItems: $maxItems);

    $user = Fixtures::makeUser('create blog entries', 'edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My Post']])
        ->assertOk();

    $entry = Entry::query()->where('collection', 'blog')->first();

    expect($entry->get('author'))->toBe($plainId ? $user->id() : [$user->id()]);

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['author' => $entry->get('author')]])
        ->assertOk()
        ->assertSee('no-op');
})->with([
    'a plain id with max_items 1' => [1, true],
    'a list without max_items' => [null, false],
]);

it("requires 'edit other authors blog entries' to name someone else as author", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $someoneElse = Fixtures::makeUser()->id();
    $user = Fixtures::makeUser('create blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Ghostwritten', 'author' => [$someoneElse]]])
        ->assertHasErrors(["author can only be you without 'edit other authors blog entries' — grant it to a role of {$user->email()} in the Control Panel, or leave author out"]);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);

    Server::actingAs(Fixtures::makeUser('create blog entries', 'edit blog entries', 'edit other authors blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Ghostwritten', 'author' => [$someoneElse]]])
        ->assertOk();

    expect(Entry::query()->where('collection', 'blog')->first()->authors()->all())->toBe([$someoneElse]);
});

it('lists the sites of the collection under localizations, filled where propagate created one', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('create blog entries', 'access en site', 'access de site');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Alone']])
        ->assertOk()
        ->assertSee('"status":"draft"},"de":null}');

    // Statamic's own propagate: Entry::save() makes a localization in every
    // other site of the collection, and the response shows them.
    Collection::findByHandle('blog')->propagate(true)->save();

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Everywhere']])
        ->assertOk();

    $origin = Entry::query()->where('collection', 'blog')->where('slug', 'everywhere')->where('site', 'en')->first();
    $localization = $origin->in('de');

    expect($localization)->not->toBeNull()
        ->and($localization->origin()->id())->toBe($origin->id())
        ->and($localization->published())->toBeFalse();

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Alone']])
        ->assertHasErrors();
});

it('refuses a file of an unexposed container in Bard and Markdown text the same whether it exists or not', function (array $data, string $field) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('private');
    Fixtures::landing();

    Blueprint::find('collections.landing.landing_page')
        ->ensureField('intro', ['type' => 'bard'])
        ->ensureField('summary', ['type' => 'bard', 'save_html' => true])
        ->ensureField('notes', ['type' => 'markdown'])
        ->ensureField('cta', ['type' => 'link'])
        ->ensureField('gallery', ['type' => 'asset_container', 'max_items' => 1])
        ->ensureField('rich', ['type' => 'bard', 'sets' => ['main' => ['sets' => ['promo' => ['fields' => [
            ['handle' => 'text', 'field' => ['type' => 'text']],
            ['handle' => 'target', 'field' => ['type' => 'link']],
        ]]]]]])
        ->ensureField('blocks', ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['note' => ['fields' => [
            ['handle' => 'heading', 'field' => ['type' => 'text']],
            ['handle' => 'footnote', 'field' => ['type' => 'markdown']],
        ]]]]]])
        ->save();

    Storage::disk('private')->put('payroll-2026.png', Fixtures::tinyPng());

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => ['images']]]);

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', ...$data]])
        ->assertHasErrors(["{$field} links a container not exposed to MCP — leave it out of data"])
        ->assertDontSee('private');

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
})->with([
    'a Bard image of an existing file' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::private::payroll-2026.png']]]]]], 'intro'],
    'a Bard image of a missing file' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::private::nope.png']]]]]], 'intro'],
    'a Bard link mark' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'the plan', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://asset::private::payroll-2026.png']]]]]]]], 'intro'],
    'Bard sent as HTML' => [['intro' => '<p><img src="asset::private::payroll-2026.png"></p>'], 'intro'],
    'a Markdown image' => [['notes' => '![chart](statamic://asset::private::payroll-2026.png)'], 'notes'],
    'a Markdown image without the asset prefix' => [['notes' => '![chart](statamic://private::payroll-2026.png)'], 'notes'],
    'a Markdown image without the asset prefix, of a missing file' => [['notes' => '![chart](statamic://private::nope.png)'], 'notes'],
    'Bard HTML with an encoded colon in the image' => [['intro' => '<p><img src="asset&#58;:private::payroll-2026.png"></p>'], 'intro'],
    'Bard HTML with encoded colons in the link' => [['intro' => '<p><a href="statamic://asset&colon;&colon;private::payroll-2026.png">plan</a></p>'], 'intro'],
    'Bard saved as HTML, without the asset prefix' => [['summary' => '<p><a href="statamic://private::payroll-2026.png">plan</a></p>'], 'summary'],
    'a link field' => [['cta' => 'asset::private::payroll-2026.png'], 'cta'],
    'a Bard image whose attributes claim to be a set' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['type' => 'set', 'src' => 'asset::private::payroll-2026.png']]]]]], 'intro'],
    'a Markdown link with an empty repository prefix' => [['notes' => '![chart](statamic://::private::payroll-2026.png)'], 'notes'],
    'a Markdown link with an empty prefix, of a missing file' => [['notes' => '![chart](statamic://::private::nope.png)'], 'notes'],
    'a Markdown link with a zero repository prefix' => [['notes' => '[x](statamic://0::private::payroll-2026.png)'], 'notes'],
    'an asset_container field naming the container' => [['gallery' => 'private'], 'gallery'],
    'an asset_container field naming no container' => [['gallery' => 'nope'], 'gallery'],
    'a term-style link into a taxonomy that is not exposed' => [['notes' => '[x](statamic://topics::payroll-2026.png)'], 'notes'],
    'a link field sent as an object' => [['cta' => ['url' => 'asset::private::payroll-2026.png']], 'cta'],
    'a link mark on a set node inside a paragraph' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'set', 'index' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://asset::private::payroll-2026.png']]]]]]]], 'intro'],
    'a Markdown link by the file URL' => [['notes' => '[x](statamic:///assets/private/payroll-2026.png)'], 'notes'],
    'a Markdown link by the URL of a missing file' => [['notes' => '[x](statamic:///assets/private/nope.png)'], 'notes'],
    'a link field by the file URL' => [['cta' => 'asset::/assets/private/payroll-2026.png'], 'cta'],
    'Bard saved as HTML, in plain text' => [['summary' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see (statamic://asset::private::payroll-2026.png) now']]]]], 'summary'],
    'Bard saved as HTML, in a link title' => [['summary' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'title' => 'statamic://asset::private::payroll-2026.png']]]]]]]], 'summary'],
    'Bard saved as HTML, single-quoted' => [['summary' => "<p><img src='statamic://asset::private::payroll-2026.png'></p>"], 'summary'],
    'a link field inside a Bard set' => [['rich' => [['type' => 'set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'promo', 'target' => 'asset::private::payroll-2026.png']]]]], 'rich'],
    'a Markdown field inside a Replicator set' => [['blocks' => [['type' => 'note', 'heading' => 'Hi', 'footnote' => '![x](statamic://private::payroll-2026.png)']]], 'blocks'],
    'a Bard link whose attributes claim to be a set' => [['intro' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'the plan', 'marks' => [['type' => 'link', 'attrs' => ['type' => 'set', 'href' => 'statamic://asset::private::payroll-2026.png']]]]]]]], 'intro'],
]);

it('stores a Bard image and a Markdown link of an exposed container, a link to a term, and a hidden container named in plain text', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('private');
    Fixtures::landing();

    Blueprint::find('collections.landing.landing_page')
        ->ensureField('intro', ['type' => 'bard'])
        ->ensureField('notes', ['type' => 'markdown'])
        ->ensureField('cta', ['type' => 'link'])
        ->ensureField('gallery', ['type' => 'asset_container', 'max_items' => 1])
        ->save();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => ['images'], 'taxonomies' => ['tags']]]);

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => [
            'title' => 'Home',
            'gallery' => 'images',
            'intro' => [
                ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::images::hero.jpg']]]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Write asset::private::file.png in the field']]],
            ],
            'notes' => '![chart](statamic://asset::images::hero.jpg) ![same](statamic://images::hero.jpg) [tag](statamic://tags::php) [docs](statamic://docs/Class::method) and asset::private::path as plain text',
            'cta' => 'asset::/assets/images/hero.jpg',
        ]])
        ->assertOk();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => [
            'title' => 'Other',
            'notes' => 'Use "statamic://entry::abc" in links, or (statamic:///assets/images/hero.jpg)',
            'cta' => 'https://[::1]/docs/Class::method',
        ]])
        ->assertOk();
});

it('refuses the two Bard shapes that would skip the checks on a set: a set spelled another way, and the old format', function (array $body, string $error) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', 'body' => $body]])
        ->assertHasErrors([$error]);

    expect(Entry::query()->where('collection', 'landing')->count())->toBe(0);
})->with([
    'Set' => [[['type' => 'Set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'callout', 'text' => 'Hi', 'bogus' => 'x']]]], "body.0 is a set: write its type as 'set'"],
    'the old format' => [[['type' => 'text', 'text' => '<p><img src="asset::private::payroll-2026.png"></p>']], "body is in Bard's old format, a list of text and set items — send ProseMirror nodes, with each set as a node of type 'set'"],
]);

it('refuses a file of an unexposed container by its URL whatever host the call arrives on', function (string $root) {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::fake('vault', ['url' => 'http://localhost/assets/vault']);
    tap(AssetContainer::make('vault')->disk('vault')->title('Vault'))->save();

    Blueprint::find('collections.landing.landing_page')
        ->ensureField('notes', ['type' => 'markdown'])
        ->ensureField('cta', ['type' => 'link'])
        ->save();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => ['images']]]);

    URL::forceRootUrl($root);

    $user = Fixtures::makeUser('create landing entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', 'notes' => '[x](statamic:///assets/vault/payroll-2026.png)']])
        ->assertHasErrors(['notes links a container not exposed to MCP — leave it out of data']);

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => ['title' => 'Home', 'cta' => 'asset::/assets/vault/payroll-2026.png']])
        ->assertHasErrors(['cta links a container not exposed to MCP — leave it out of data']);
})->with(['http://localhost', 'http://other.test']);
