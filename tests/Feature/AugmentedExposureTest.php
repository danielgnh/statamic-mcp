<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

function blogPostWithRelations(array $data): string
{
    Blueprint::find('collections.blog.article')
        ->ensureField('related', ['type' => 'entries', 'collections' => ['blog', 'pages']])
        ->ensureField('featured', ['type' => 'entries', 'collections' => ['pages'], 'max_items' => 1])
        ->ensureField('cta', ['type' => 'link'])
        ->save();

    return tap(Entry::make()->collection('blog')->locale('en')->slug('post')->data(['title' => 'Post', ...$data]))->save()->id();
}

it('leaves related entries of an unexposed collection out of augmented entries_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    $offer = Fixtures::page('spring-offer', 'Spring offer');
    $other = tap(Entry::make()->collection('blog')->locale('en')->slug('other')->data(['title' => 'Other post']))->save()->id();
    $post = blogPostWithRelations(['related' => [$offer, $other], 'featured' => $offer, 'cta' => "entry::{$offer}"]);

    config(['statamic.mcp.resources' => ['collections' => ['blog']]]);

    $user = Fixtures::makeUser('view blog entries', 'view pages entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $post, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Spring offer')
        ->assertDontSee('/spring-offer')
        ->assertSee('"featured":null')
        ->assertSee('"cta":{"url":null}');

    $this->actingAs($user);

    $data = json_decode((string) (new EntriesGet)->handle(new Request(['id' => $post, 'format' => 'augmented']))->content(), true);

    expect(collect(data_get($data, 'data.related'))->pluck('title', 'id')->all())->toBe([$other => 'Other post']);
});

it('keeps exposed related entries the caller cannot view, as the Control Panel listing shows their titles', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    $post = blogPostWithRelations(['related' => [Fixtures::page('spring-offer', 'Spring offer')]]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $post, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"title":"Spring offer"');
});

it('leaves related terms of an unexposed taxonomy out of augmented entries_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Term::make()->taxonomy('tags')->slug('layoffs')->data(['title' => 'Planned layoffs'])->save();

    $post = tap(Entry::make()->collection('blog')->locale('en')->slug('post')
        ->data(['title' => 'Post', 'topic' => 'layoffs']))->save()->id();

    config(['statamic.mcp.resources' => ['collections' => ['blog'], 'taxonomies' => []]]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'view tags terms'))
        ->tool(EntriesGet::class, ['id' => $post, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Planned layoffs')
        ->assertDontSee('/tags/layoffs')
        ->assertSee('"topic":null');
});

it('leaves assets of an unexposed container out of augmented entries_get, inside sets too', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    $image = [['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1]]];

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        'blocks' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['picture' => ['fields' => $image]]]]],
        'gallery' => ['type' => 'grid', 'fields' => $image],
        'seo' => ['type' => 'group', 'fields' => $image],
        'body' => ['type' => 'bard', 'sets' => ['main' => ['sets' => ['picture' => ['fields' => $image]]]]],
        'download' => ['type' => 'link'],
    ])->setHandle('landing_page')->setNamespace('collections.landing')->save();

    Storage::disk('images')->put('payroll-2026.png', Fixtures::tinyPng());

    AssetContainer::findByHandle('images')->asset('payroll-2026.png')->data(['alt' => 'Salary table'])->save();

    $entry = tap(Entry::make()->collection('landing')->locale('en')->slug('a')->data([
        'title' => 'A',
        'hero' => 'payroll-2026.png',
        'blocks' => [['id' => 'blk00001', 'type' => 'picture', 'enabled' => true, 'image' => 'payroll-2026.png']],
        'gallery' => [['id' => 'row00001', 'image' => 'payroll-2026.png']],
        'seo' => ['image' => 'payroll-2026.png'],
        'body' => [['type' => 'set', 'attrs' => ['id' => 'bard0001', 'values' => ['type' => 'picture', 'image' => 'payroll-2026.png']]]],
        'download' => 'asset::images::payroll-2026.png',
    ]))->save()->id();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => []]]);

    Server::actingAs(Fixtures::makeUser('view landing entries', 'view images assets'))
        ->tool(EntriesGet::class, ['id' => $entry, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Salary table')
        ->assertDontSee('payroll-2026.png')
        ->assertSee('"hero":null')
        ->assertSee('"blocks":[{"image":null,"id":"blk00001","type":"picture"}]')
        ->assertSee('"gallery":[{"image":null,"id":"row00001"}]')
        ->assertSee('"seo":{"image":null}')
        ->assertSee('"body":[{"image":null,"id":"bard0001","type":"picture"}]')
        ->assertSee('"download":{"url":null}');
});

it('leaves relations outside the exposure out of augmented terms_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::pages();

    Taxonomy::make('regions')->title('Regions')->save();
    Term::make()->taxonomy('regions')->slug('north')->data(['title' => 'Northern region'])->save();

    Blueprint::find('taxonomies.tags.tag')
        ->ensureField('landing', ['type' => 'entries', 'collections' => ['pages']])
        ->ensureField('region', ['type' => 'terms', 'taxonomies' => ['regions']])
        ->save();

    Term::make()->taxonomy('tags')->slug('news')->data([
        'title' => 'News', 'landing' => [Fixtures::page('spring-offer', 'Spring offer')], 'region' => ['north'],
    ])->save();

    config(['statamic.mcp.resources' => ['collections' => [], 'taxonomies' => ['tags']]]);

    Server::actingAs(Fixtures::makeUser('view tags terms', 'view pages entries', 'view regions terms'))
        ->tool(TermsGet::class, ['id' => 'tags::news', 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Spring offer')
        ->assertDontSee('Northern region')
        ->assertSee('"landing":[]')
        ->assertSee('"region":[]');
});

it('leaves Bard and Markdown links to an unexposed entry or asset without their URL', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Blueprint::find('collections.landing.landing_page')
        ->ensureField('intro', ['type' => 'bard'])
        ->ensureField('notes', ['type' => 'markdown'])
        ->save();

    Storage::disk('images')->put('payroll-2026.png', Fixtures::tinyPng());

    AssetContainer::findByHandle('images')->asset('payroll-2026.png')->data(['alt' => 'Salary table'])->save();

    $offer = Fixtures::page('spring-offer', 'Spring offer');
    $text = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see the offer', 'marks' => [['type' => 'link', 'attrs' => ['href' => "statamic://entry::{$offer}"]]]]]],
        ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::images::payroll-2026.png']]]],
    ];

    $entry = tap(Entry::make()->collection('landing')->locale('en')->slug('a')->data([
        'title' => 'A',
        'intro' => $text,
        'body' => [...$text, ['type' => 'set', 'attrs' => ['id' => 'bard0001', 'values' => ['type' => 'callout', 'text' => 'Call us']]]],
        'notes' => "[see the offer](statamic://entry::{$offer}) ![chart](statamic://asset::images::payroll-2026.png)",
    ]))->save()->id();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => []]]);

    Server::actingAs(Fixtures::makeUser('view landing entries', 'view pages entries', 'view images assets'))
        ->tool(EntriesGet::class, ['id' => $entry, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('/spring-offer')
        ->assertDontSee('payroll-2026.png')
        ->assertDontSee('Salary table')
        ->assertSee('"intro":"<p><a>see the offer</a></p><p><img></p>"')
        ->assertSee('"body":[{"type":"text","text":"<p><a>see the offer</a></p><p><img></p>"},{"text":"Call us","id":"bard0001","type":"callout"}]')
        ->assertSee('<a href=\"\">see the offer</a> <img src=\"\" alt=\"chart\" />');
});

it('leaves links and images in Statamic 2 Bard data without their URL', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('payroll-2026.png', Fixtures::tinyPng());

    AssetContainer::findByHandle('images')->asset('payroll-2026.png')->data(['alt' => 'Salary table'])->save();

    $offer = Fixtures::page('spring-offer', 'Spring offer');

    $entry = tap(Entry::make()->collection('landing')->locale('en')->slug('a')->data([
        'title' => 'A',
        'body' => [
            ['type' => 'text', 'text' => "<p><a href='statamic://entry::{$offer}'>single</a> <a href=\"statamic&#58;//entry::{$offer}\">encoded</a> <img src=\"asset::images::payroll-2026.png\"></p>"],
            ['type' => 'callout', 'text' => 'Call us'],
        ],
    ]))->save()->id();

    config(['statamic.mcp.resources' => ['collections' => ['landing'], 'asset_containers' => []]]);

    Server::actingAs(Fixtures::makeUser('view landing entries', 'view pages entries', 'view images assets'))
        ->tool(EntriesGet::class, ['id' => $entry, 'format' => 'augmented', 'fields' => ['body']])
        ->assertOk()
        ->assertDontSee('/spring-offer')
        ->assertDontSee('payroll-2026.png')
        ->assertDontSee('Salary table')
        ->assertSee('<p><a>single</a> <a>encoded</a> <img></p>')
        ->assertSee('"text":"Call us"');
});

it('leaves an unexposed collection mounted on an entry out of augmented entries_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    $home = Fixtures::page('home', 'Home');

    Collection::findByHandle('blog')->mount($home)->save();

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('"handle":"blog"')
        ->assertSee('"mount":null');
});

it('leaves collections, taxonomies, navigations, forms, and containers outside the exposure out of augmented entries_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::form('contact');
    Fixtures::assetContainer('images');

    Blueprint::find('collections.pages.page')
        ->ensureField('sources', ['type' => 'collections'])
        ->ensureField('vocabulary', ['type' => 'taxonomies'])
        ->ensureField('menus', ['type' => 'navs'])
        ->ensureField('signup', ['type' => 'form', 'max_items' => 1])
        ->ensureField('library', ['type' => 'asset_container', 'max_items' => 1])
        ->ensureField('trees', ['type' => 'structures'])
        ->save();

    $home = Fixtures::page('home', 'Home');

    Entry::find($home)->merge([
        'sources' => ['blog', 'pages'], 'vocabulary' => ['tags'], 'menus' => ['main'], 'signup' => 'contact', 'library' => 'images',
        'trees' => ['collection::blog', 'main'],
    ])->save();

    config(['statamic.mcp.resources' => ['collections' => ['pages'], 'taxonomies' => [], 'navigations' => [], 'forms' => [], 'asset_containers' => []]]);

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('"handle":"blog"')
        ->assertDontSee('"handle":"tags"')
        ->assertDontSee('"handle":"main"')
        ->assertDontSee('"handle":"contact"')
        ->assertDontSee('"handle":"images"')
        ->assertSee('"sources":[{"title":"Pages","handle":"pages"}]')
        ->assertSee('"vocabulary":[]')
        ->assertSee('"menus":[]')
        ->assertSee('"signup":null')
        ->assertSee('"library":null')
        ->assertSee('"trees":[]');
});

it('returns the parent of an entry as a stub, as Statamic\'s REST API does, not the whole entry', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure('pages');

    Blueprint::find('collections.pages.page')
        ->ensureField('parent', ['type' => 'entries', 'collections' => ['pages'], 'max_items' => 1])
        ->ensureField('summary', ['type' => 'text'])
        ->save();

    $parent = Fixtures::page('about', 'About');
    $child = Fixtures::page('team', 'Team');

    Entry::find($parent)->merge(['summary' => 'Parent summary'])->save();
    Collection::findByHandle('pages')->structure()->in('en')
        ->tree([['entry' => $parent, 'children' => [['entry' => $child]]]])
        ->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $child, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Parent summary')
        ->assertSee('"parent":{"id":"'.$parent.'","title":"About","url":"/about","permalink":"http://localhost/about","api_url":null}');
});
