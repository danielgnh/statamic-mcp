<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesLocalize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User;
use Statamic\Events\EntryCreating;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

function makeBlogOrigin(array $data = [], string $slug = 'hello'): Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug($slug)
            ->locale('en')
            ->data(['title' => 'Hello', 'hero_image' => 'hero.jpg', ...$data])
            ->published(true)
    )->save();
}

function localizer(string ...$permissions): User
{
    return Fixtures::makeUser('edit blog entries', 'access en site', 'access de site', ...$permissions);
}

it('adds an entry to another site as a draft that inherits its fields', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();
    $user = localizer();

    Server::actingAs($user)
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertOk()
        ->assertSee('"slug":"hello"')
        ->assertSee('"site":"de"')
        ->assertSee('"origin_id":"'.$origin->id().'"')
        ->assertSee('"status":"draft"')
        ->assertSee('"url":"/de/blog/hello"')
        ->assertSee('saved as draft — not live')
        ->assertSee('"cp_edit_url"');

    $localization = $origin->in('de');

    expect($localization)->not->toBeNull()
        ->and($localization->origin()->id())->toBe($origin->id())
        ->and($localization->published())->toBeFalse()
        ->and($localization->data()->has('title'))->toBeFalse()
        ->and($localization->value('title'))->toBe('Hello')
        ->and($localization->value('hero_image'))->toBe('hero.jpg')
        ->and($localization->get('updated_by'))->toBe($user->id());
});

it('stores the values passed in data as the localization\'s own and inherits the rest', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'data' => ['title' => 'Hallo']])
        ->assertOk();

    $localization = $origin->in('de');

    expect($localization->get('title'))->toBe('Hallo')
        ->and($localization->data()->has('hero_image'))->toBeFalse()
        ->and($localization->value('hero_image'))->toBe('hero.jpg');
});

it('takes a slug of its own, normalized for the target site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'slug' => 'Über uns'])
        ->assertOk()
        ->assertSee('"slug":"ueber-uns"')
        ->assertSee('"url":"/de/blog/ueber-uns"');

    expect($origin->in('de')->slug())->toBe('ueber-uns');
});

it('localizes an entry of a collection with slugs turned off, which has no slug', function () {
    Fixtures::multisite();
    Fixtures::faqs();

    $origin = Fixtures::faq('How long does delivery take?');

    Server::actingAs(Fixtures::makeUser('edit faqs entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $origin, 'site' => 'de', 'data' => ['title' => 'Wie lange dauert die Lieferung?']])
        ->assertOk()
        ->assertSee('"slug":null');

    // Statamic names the file of an entry without a slug by its id.
    Stache::clear();

    $localization = Entry::find($origin)->in('de');

    expect($localization->slug())->toBeNull()
        ->and($localization->path())->toEndWith("/faqs/de/{$localization->id()}.md");
});

it('refuses a slug on a collection with slugs turned off', function () {
    Fixtures::multisite();
    Fixtures::faqs();

    $origin = Fixtures::faq('How long does delivery take?');

    Server::actingAs(Fixtures::makeUser('edit faqs entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $origin, 'site' => 'de', 'slug' => 'lieferzeit'])
        ->assertHasErrors(["collection 'faqs' has slugs turned off, so its entries have none — omit slug"]);

    expect(Entry::find($origin)->in('de'))->toBeNull();
});

it('lists the entry in every site of the collection the user can access', function () {
    Fixtures::multisite(withThirdSite: true);
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertOk()
        ->assertSee(sprintf(
            '"localizations":{"en":{"id":"%s","status":"published"},"de":{"id":"%s","status":"draft"}}',
            $origin->id(),
            $origin->in('de')->id(),
        ))
        ->assertDontSee('"at"');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'at'])
        ->assertOk()
        ->assertSee(sprintf('"de":{"id":"%s","status":"draft"},"at":{"id":"%s","status":"draft"}}', $origin->in('de')->id(), $origin->in('at')->id()));
});

it('localizes from the root entry when the collection says so, and from the entry passed otherwise', function () {
    Fixtures::multisite(withThirdSite: true);
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();
    $de = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    // select, the default: the agent picks the origin by passing its id.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $de->id(), 'site' => 'at'])
        ->assertOk()
        ->assertSee('"origin_id":"'.$de->id().'"');

    expect($origin->in('at')->origin()->id())->toBe($de->id());

    $origin->in('at')->delete();

    Collection::findByHandle('blog')->originBehavior('root')->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $de->id(), 'site' => 'at'])
        ->assertOk()
        ->assertSee('"origin_id":"'.$origin->id().'"');

    expect($origin->in('at')->origin()->id())->toBe($origin->id())
        ->and($origin->in('at')->value('title'))->toBe('Hello');
});

it('refuses a site the entry is already in, naming the entry there', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();
    $localization = tap($origin->makeLocalization('de'))->save();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(["entry '{$origin->id()}' is already in site 'de' as entry '{$localization->id()}' — change that one with entries_update"]);

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'en'])
        ->assertHasErrors(["entry '{$origin->id()}' is in site 'en' itself — pass another site of collection 'blog': de"]);
});

it('refuses a site the collection is not in, and one that does not exist', function () {
    Fixtures::multisite();

    tap(
        Collection::make('docs')->title('Docs')->sites(['en'])->routes('/docs/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required', 'localizable' => true],
    ])->setHandle('doc')->setNamespace('collections.docs')->save();

    $doc = tap(Entry::make()->collection('docs')->slug('intro')->locale('en')->data(['title' => 'Intro']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $doc->id(), 'site' => 'de'])
        ->assertHasErrors(["collection 'docs' is not available in site 'de' — available sites: en"]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $doc->id(), 'site' => 'fr'])
        ->assertHasErrors(["site 'fr' not found — available: de, en"]);
});

it("requires 'access {site} site' for the target site", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();
    $user = Fixtures::makeUser('edit blog entries', 'access en site');

    Server::actingAs($user)
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect($origin->in('de'))->toBeNull();
});

it('requires the edit permission for the origin, with the author rule', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $origin = makeBlogOrigin(['author' => Fixtures::makeSuper()->id()]);

    $viewer = Fixtures::makeUser('view blog entries', 'access en site', 'access de site');

    Server::actingAs($viewer)
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(["requires 'edit other authors blog entries' — grant it to a role of {$viewer->email()} in the Control Panel"]);

    Server::actingAs(localizer('edit other authors blog entries'))
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertOk();
});

it('refuses a value of its own for a field that is not localizable', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Blueprint::find('collections.blog.article')->ensureFieldHasConfig('hero_image', ['localizable' => false])->save();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'data' => ['title' => 'Hallo', 'hero_image' => 'hallo.jpg']])
        ->assertHasErrors(["field hero_image is not localizable, so every site shows the value of its origin entry '{$origin->id()}' — change it there, or turn on Localizable for the field in blueprint 'article' to translate it here"]);

    expect($origin->in('de'))->toBeNull();
});

it('validates its own values with the ones it inherits', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'data' => ['titel' => 'Hallo']])
        ->assertHasErrors(["unknown field titel — valid handles: content, hero_image, title, topic — did you mean 'title' instead of 'titel'?"]);

    // title is required and only inherited: the data alone never has it.
    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'data' => ['hero_image' => 'hallo.jpg']])
        ->assertOk();

    expect($origin->in('de')->data()->has('title'))->toBeFalse();
});

it('refuses a URL another entry of the target site already has', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    // A separate entry, created directly in de: not a localization of anything.
    $taken = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('de')->data(['title' => 'Hallo'])->published(true)
    )->save();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(["URL '/blog/hello' already belongs to entry '{$taken->id()}' in collection 'blog' — pick another slug"]);

    expect($origin->in('de'))->toBeNull();
});

it('localizes a page under a parent that has the same slug in the target site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::structure();

    $rental = Fixtures::page('rental', 'Rental');
    $delivery = Fixtures::page('delivery', 'Delivery');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $rental, 'children' => [['entry' => $delivery]]]])->save();

    // The German parent's slug happens to be the one its child keeps.
    $rentalDe = tap(Entry::find($rental)->makeLocalization('de')->slug('delivery'))->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $delivery, 'site' => 'de'])
        ->assertOk()
        ->assertSee('"slug":"delivery"')
        ->assertSee('"url":"/de/delivery/delivery"')
        ->assertSee(sprintf('"parent":"%s"', $rentalDe->id()));
});

it('rejects published and date, which a localization never takes', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'published' => true])
        ->assertHasErrors(['published is not accepted by entries_localize — publish state changes only through entries_publish and entries_unpublish']);

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de', 'date' => '2026-10-06'])
        ->assertHasErrors(['a localization inherits the date of its origin — omit date; when the date field is localizable, set it afterwards with entries_update']);

    expect($origin->in('de'))->toBeNull();
});

it('inherits the date of a dated origin', function () {
    Fixtures::multisite();
    Fixtures::news();

    $origin = tap(
        Entry::make()->collection('news')->slug('launch')->locale('en')->data(['title' => 'Launch'])->date(Carbon::parse('2026-10-06 09:00'))->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('edit news entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertOk()
        ->assertSee('"date":"2026-10-06T09:00:00+00:00"');

    expect($origin->in('de')->date()->equalTo($origin->date()))->toBeTrue();
});

it('places the localization in the target site tree under the localized parent', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about, 'children' => [['entry' => $team]]]])->save();

    $aboutDe = tap(Entry::find($about)->makeLocalization('de'))->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $team, 'site' => 'de', 'data' => ['title' => 'Team']])
        ->assertOk()
        ->assertSee('"url":"/de/about/team"')
        ->assertSee(sprintf('"parent":"%s"', $aboutDe->id()));

    expect(Fixtures::storedPagesTree('de'))->toBe([
        ['entry' => $aboutDe->id(), 'children' => [['entry' => Fixtures::pageId('team', 'de')]]],
    ]);
});

it('places it at the top level when the parent has no localization in that site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about, 'children' => [['entry' => $team]]]])->save();

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'access en site', 'access de site'))
        ->tool(EntriesLocalize::class, ['id' => $team, 'site' => 'de'])
        ->assertOk()
        ->assertSee('"url":"/de/team"')
        ->assertSee('"parent":null');

    expect(Fixtures::storedPagesTree('de'))->toBe([['entry' => Fixtures::pageId('team', 'de')]]);
});

it('records an initial revision on revision-enabled collections', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $origin = makeBlogOrigin();
    $user = localizer();

    Server::actingAs($user)
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertOk()
        ->assertSee('"status":"draft"');

    $revisions = $origin->in('de')->revisions();

    expect($revisions)->toHaveCount(1)
        ->and($revisions->first()->message())->toBe('Created via MCP (entries_localize)')
        ->and($revisions->first()->user()->id())->toBe($user->id());
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    Event::listen(EntryCreating::class, fn () => false);

    Server::actingAs(localizer())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(['the save was cancelled by a listener on this site — nothing was created']);

    expect($origin->in('de'))->toBeNull();
});

it('is hidden on a single-site install, and refuses a stale-cached call there', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $origin = makeBlogOrigin();

    // The harness honors shouldRegister(): the tool is not there to call.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'en'])
        ->assertHasErrors();

    $response = (new EntriesLocalize)->handle(new Request(['id' => $origin->id(), 'site' => 'en']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())
        ->toContain('this install has one site, so there is no other site to add an entry to — entries_localize is available once multisite is on (statamic.system.multisite, a Statamic Pro feature)');
});

it('is refused in read_only mode', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.read_only' => true]);

    $origin = makeBlogOrigin();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesLocalize::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors();

    expect($origin->in('de'))->toBeNull();
});
