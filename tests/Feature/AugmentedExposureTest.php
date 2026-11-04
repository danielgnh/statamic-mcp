<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Laravel\Mcp\Request;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Fields\Values;

enum StringBackedForTest: string
{
    case Draft = 'draft';
}

function startNewRequest(): void
{
    Blink::flush();
}

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

    startNewRequest();

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

it('keeps the stored id of an unexposed entry in raw entries_get, without its title, and answers not found for that id', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    $offer = Fixtures::page('spring-offer', 'Spring offer');
    $post = blogPostWithRelations(['related' => [$offer]]);

    config(['statamic.mcp.resources' => ['collections' => ['blog']]]);

    startNewRequest();

    $user = Fixtures::makeUser('view blog entries', 'view pages entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $post])
        ->assertOk()
        ->assertSee($offer)
        ->assertDontSee('Spring offer');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $offer])
        ->assertHasErrors(["entry '{$offer}' not found"]);
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

it('filters computed values holding many entries or users by exposure and the view users permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    tap(Entry::make()->collection('blog')->locale('en')->slug('confidential')->data([
        'title' => 'Confidential post',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Secret body text']]]],
    ]))->save();

    Collection::computed('pages', 'posts', fn () => Entry::query()->where('collection', 'blog')->get());
    Collection::computed('pages', 'post_list', fn () => Entry::query()->where('collection', 'blog')->get()->all());
    Collection::computed('pages', 'nested', fn () => ['featured' => ['items' => Entry::query()->where('collection', 'blog')->get()], 'count' => 2]);
    Collection::computed('pages', 'siblings', fn () => Entry::query()->where('collection', 'pages')->where('slug', 'about')->get());
    Collection::computed('pages', 'editors', fn () => User::all());

    $editor = tap(Fixtures::makeSuper()->set('name', 'Other Editor'))->save();

    $home = Fixtures::page('home', 'Home');
    Fixtures::page('about', 'About us');

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"posts":[]')
        ->assertSee('"post_list":[]')
        ->assertSee('"nested":{"featured":{"items":[]},"count":2}')
        ->assertSee('About us')
        ->assertSee('Other Editor')
        ->assertDontSee('Confidential post')
        ->assertDontSee('Secret body text')
        ->assertDontSee('/blog/confidential')
        ->assertDontSee($editor->email())
        ->assertDontSee('"super"');
});

it('reduces computed global variables and submissions to stubs, and drops unexposed ones', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::settings();
    Fixtures::form();
    Fixtures::submission('contact', ['name' => 'Visitor', 'email' => 'visitor@site.test', 'message' => 'Private message']);

    Collection::computed('pages', 'site_settings', fn () => GlobalSet::find('settings')->inDefaultSite());
    Collection::computed('pages', 'messages', fn () => Form::find('contact')->submissions());

    $home = Fixtures::page('home', 'Home');
    $user = Fixtures::makeUser('view pages entries');

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"site_settings":null')
        ->assertSee('"messages":[]')
        ->assertDontSee('Acme')
        ->assertDontSee('visitor@site.test');

    config(['statamic.mcp.resources' => ['collections' => ['pages'], 'globals' => ['settings'], 'forms' => ['contact']]]);

    startNewRequest();

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"site_settings":{')
        ->assertSee('"messages":[{')
        ->assertDontSee('Acme')
        ->assertDontSee('visitor@site.test')
        ->assertDontSee('Private message');
});

it('renders a Bard image of an unexposed container the same whether the file exists or not', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('private');
    Fixtures::pages();

    Storage::disk('private')->put('payroll-2026.png', Fixtures::tinyPng());
    Storage::disk('images')->put('public.png', Fixtures::tinyPng());

    Blueprint::makeFromFields(['title' => ['type' => 'text'], 'body' => ['type' => 'bard']])
        ->setHandle('page')->setNamespace('collections.pages')->save();

    config([
        'statamic.mcp.resources.collections' => ['pages'],
        'statamic.mcp.resources.asset_containers' => ['images'],
    ]);

    $image = fn (string $src) => ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => $src, 'alt' => 'guess']]]];

    $entry = tap(Entry::make()->collection('pages')->slug('home')->data(['title' => 'Home', 'body' => [
        $image('asset::private::payroll-2026.png'),
        $image('asset::private::nope.png'),
        $image('asset::images::public.png'),
    ]]))->save();

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'format' => 'augmented', 'fields' => ['body']])
        ->assertOk()
        ->assertSee('<p><img></p><p><img></p><p><img src=\"/assets/images/public.png\" alt=\"guess\"></p>');
});

function pageWithComputedSummary(string $sourceCollection, string $shape): array
{
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'owner' => ['type' => 'users'],
        'related' => ['type' => 'entries', 'collections' => ['blog']],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Blueprint::find('collections.blog.article')
        ->ensureField('owner', ['type' => 'users'])
        ->ensureField('related', ['type' => 'entries', 'collections' => ['blog']])
        ->save();

    Collection::computed('pages', 'summary', function () use ($sourceCollection, $shape) {
        $source = Entry::query()->where('collection', $sourceCollection)->where('slug', 'source')->first();

        return $shape === 'collection'
            ? $source->toAugmentedCollection(['title', 'related', 'owner'])
            : $source->toAugmentedArray(['title', 'related', 'owner']);
    });

    $owner = tap(Fixtures::makeSuper()->set('name', 'Owner'))->save();
    $reader = Fixtures::page('reader', 'Reader');

    $hidden = tap(Entry::make()->collection('blog')->locale('en')->slug('hidden-post')->data(['title' => 'Hidden post']))->save();

    Entry::make()->collection($sourceCollection)->locale('en')->slug('source')
        ->data(['title' => 'Source title', 'owner' => [$owner->id()], 'related' => [$hidden->id()]])
        ->save();

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    return [$reader, $owner];
}

it('drops every value of a computed augmented entry from an unexposed collection', function (string $shape) {
    [$reader, $owner] = pageWithComputedSummary('blog', $shape);

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $reader, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Source title')
        ->assertDontSee('Hidden post')
        ->assertDontSee($owner->email())
        ->assertDontSee('"super"');
})->with(['collection', 'array']);

it('presents the values of a computed augmented entry from an exposed collection like any other', function (string $shape) {
    [$reader, $owner] = pageWithComputedSummary('pages', $shape);

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $reader, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"title":"Source title"')
        ->assertSee('"related":[]')
        ->assertSee('Owner')
        ->assertDontSee('Hidden post')
        ->assertDontSee($owner->email())
        ->assertDontSee('"super"');
})->with(['collection', 'array']);

it('keeps the shape of a computed collection holding plain arrayable objects', function () {
    Fixtures::site();
    Fixtures::pages();

    Collection::computed('pages', 'prices', fn () => collect([new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['label' => 'ten'];
        }
    }]));

    $home = Fixtures::page('home', 'Home');

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"prices":[{"label":"ten"}]');
});

it('filters unexposed entries out of a computed value however it wraps them', function (string $shape) {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    Entry::make()->collection('blog')->locale('en')->slug('confidential')->data([
        'title' => 'Confidential post',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Secret body text']]]],
    ])->save();

    Collection::computed('pages', 'wrapped', function () use ($shape) {
        $posts = Entry::query()->where('collection', 'blog');

        return match ($shape) {
            'paginator' => $posts->paginate(5),
            'lazy collection' => LazyCollection::make($posts->get()->all()),
            'augmented arrays' => $posts->get()->toAugmentedArray(),
            'augmented collections' => $posts->get()->toAugmentedCollection(),
            'json serializable object' => new readonly class($posts->get()) implements JsonSerializable
            {
                public function __construct(private mixed $posts) {}

                public function jsonSerialize(): array
                {
                    return ['posts' => $this->posts];
                }
            },
            'arrayable object' => new readonly class($posts->get()) implements Arrayable
            {
                public function __construct(private mixed $posts) {}

                public function toArray(): array
                {
                    return ['posts' => $this->posts];
                }
            },
        };
    });

    $home = Fixtures::page('home', 'Home');

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Confidential post')
        ->assertDontSee('Secret body text')
        ->assertDontSee('/blog/confidential');
})->with(['paginator', 'lazy collection', 'augmented arrays', 'augmented collections', 'json serializable object', 'arrayable object']);

it('keeps augmented arrays of users and of unexposed global sets out of computed values', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::settings();

    Collection::computed('pages', 'people', fn () => User::all()->map->toAugmentedArray()->all());
    Collection::computed('pages', 'site_settings', fn () => GlobalSet::find('settings')->inDefaultSite()->toAugmentedArray());

    $editor = tap(Fixtures::makeSuper()->set('name', 'Other Editor'))->save();
    $home = Fixtures::page('home', 'Home');

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee($editor->email())
        ->assertDontSee('"super":true')
        ->assertDontSee('Acme');
});

it('keeps link and text branches of a computed navigation, without an entry to check, and drops unexposed ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::nav();

    $post = tap(Entry::make()->collection('blog')->locale('en')->slug('hidden-post')->data(['title' => 'Hidden post']))->save();

    Collection::computed('pages', 'menu', fn () => Nav::find('main')->in('en')->pages()->all());

    $home = Fixtures::page('home', 'Home');

    Nav::find('main')->makeTree('en', [
        ['id' => 'link', 'title' => 'Docs', 'url' => 'https://docs.example.com'],
        ['id' => 'text', 'title' => 'Heading'],
        ['id' => 'page', 'entry' => $home],
        ['id' => 'post', 'entry' => $post->id()],
        ['id' => 'gone', 'entry' => 'no-such-entry'],
    ])->save();

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('https://docs.example.com')
        ->assertSee('Heading')
        ->assertDontSee('Hidden post')
        ->assertDontSee('hidden-post');
});

it('filters unexposed entries and users out of computed plain objects', function (string $shape) {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();

    Entry::make()->collection('blog')->locale('en')->slug('confidential')->data(['title' => 'Confidential post'])->save();

    $editor = tap(Fixtures::makeSuper()->set('name', 'Other Editor'))->save();

    Collection::computed('pages', 'wrapped', function () use ($shape) {
        $items = ['posts' => Entry::query()->where('collection', 'blog')->get()->all(), 'editors' => User::all()];

        return match ($shape) {
            'object' => (object) $items,
            'public properties' => new readonly class($items)
            {
                public function __construct(public array $items) {}
            },
            'array object' => new ArrayObject($items),
            'array iterator' => new ArrayIterator($items),
            'values' => new Values($items),
        };
    });

    $home = Fixtures::page('home', 'Home');

    config(['statamic.mcp.resources' => ['collections' => ['pages']]]);

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('Confidential post')
        ->assertDontSee($editor->email())
        ->assertDontSee('"super"');
})->with(['object', 'public properties', 'array object', 'array iterator', 'values']);

it('keeps the shape of computed plain values and objects that hold nothing to filter', function () {
    Fixtures::site();
    Fixtures::pages();

    Collection::computed('pages', 'plain', fn () => [
        'number' => 42,
        'list' => ['a', 'b'],
        'map' => ['x' => 1],
        'date' => Carbon::parse('2026-01-02 03:04:05', 'UTC'),
        'enum' => StringBackedForTest::Draft,
        'object' => (object) ['name' => 'Ann'],
    ]);

    $home = Fixtures::page('home', 'Home');

    startNewRequest();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(EntriesGet::class, ['id' => $home, 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"plain":{"number":42,"list":["a","b"],"map":{"x":1},"date":"2026-01-02T03:04:05.000000Z","enum":"draft","object":{"name":"Ann"}}');
});
