<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesPreview;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\CP\LivePreview;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Token;
use Statamic\Testing\Concerns\FakesViews;
use Statamic\Tokens\Handlers\LivePreview as LivePreviewHandler;

uses(FakesViews::class);

beforeEach(function () {
    File::deleteDirectory(storage_path('statamic/tokens'));

    $this->withStandardFakeViews();
    $this->viewShouldReturnRaw('default', '<h1>{{ title }}</h1>');
});

afterEach(function () {
    // Live-preview tokens are files under storage_path(), which resolves into vendor/orchestra.
    File::deleteDirectory(storage_path('statamic/tokens'));
});

function makePreviewableDraft(): EntryContract
{
    return tap(
        Entry::make()->collection('blog')->slug('draft-post')->data(['title' => 'Draft'])->published(false)
    )->save();
}

function makePreviewableLive(): EntryContract
{
    return tap(
        Entry::make()->collection('blog')->slug('live-post')->data(['title' => 'Live Title'])->published(true)
    )->save();
}

/**
 * Runs entries_preview as $user and returns the decoded payload. The guards
 * are reset afterwards, so the frontend requests that follow are guests.
 *
 * @return array<string, mixed>
 */
function previewPayload(UserContract $user, array $arguments): array
{
    test()->actingAs($user);

    $response = (new EntriesPreview)->handle(new Request($arguments));

    Auth::forgetGuards();

    expect($response->isError())->toBeFalse();

    return json_decode((string) $response->content(), true);
}

function previewToken(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

function mintedPreviewTokens(): array
{
    return File::glob(storage_path('statamic/tokens/*.yaml'));
}

it('returns a live-preview url tokenized with the entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    $payload = previewPayload(Fixtures::makeUser('edit blog entries'), ['id' => $entry->id()]);

    expect($payload['id'])->toBe($entry->id())
        ->and($payload['target'])->toBe('Entry')
        ->and($payload['targets'])->toBe(['Entry'])
        ->and($payload['url'])->toMatch('#^http://localhost/blog/draft-post\?live-preview=[A-Za-z0-9]{16}&token=[A-Za-z0-9]{40}$#');

    $token = Token::find(previewToken($payload['url']));
    $item = app(LivePreview::class)->item($token);

    expect($token->handler())->toBe(LivePreviewHandler::class)
        ->and($item->id())->toBe($entry->id())
        ->and($item->getSupplement('live_preview'))->toBeTrue();

    // The supplement lands on a copy, never on the Stache instance.
    expect(Entry::find($entry->id())->hasSupplement('live_preview'))->toBeFalse();
});

it('renders the draft at the preview url for anyone who has it', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    $url = previewPayload(Fixtures::makeUser('edit blog entries'), ['id' => $entry->id()])['url'];

    $this->get('/blog/draft-post')->assertNotFound();

    $this->get($url)
        ->assertOk()
        ->assertSee('<h1>Draft</h1>', false)
        ->assertHeader('X-Statamic-Draft')
        ->assertHeader('X-Statamic-Live-Preview');
});

it('opens only the previewed entry, not other drafts', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    tap(Entry::make()->collection('blog')->slug('other-draft')->data(['title' => 'Other'])->published(false))->save();

    $token = previewToken(previewPayload(Fixtures::makeUser('edit blog entries'), ['id' => $entry->id()])['url']);

    $this->get("/blog/draft-post?token={$token}")->assertOk();
    $this->get("/blog/other-draft?token={$token}")->assertNotFound();
});

it("requires 'edit blog entries', the permission the CP checks for live preview", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();
    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesPreview::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'edit blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(mintedPreviewTokens())->toBeEmpty();
});

it("requires 'edit other authors blog entries' to preview someone else's entry, but not one's own", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $user = Fixtures::makeUser('edit blog entries');
    $theirs = tap(makePreviewableDraft()->set('author', Fixtures::makeUser()->id()))->save();
    $mine = tap(makePreviewableLive()->set('author', $user->id()))->save();

    // CP parity: PreviewController authorizes 'update', EntryPolicy's author rule.
    Server::actingAs($user)
        ->tool(EntriesPreview::class, ['id' => $theirs->id()])
        ->assertHasErrors(["requires 'edit other authors blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(mintedPreviewTokens())->toBeEmpty()
        ->and(previewPayload($user, ['id' => $mine->id()])['id'])->toBe($mine->id())
        ->and(previewPayload(Fixtures::makeUser('edit blog entries', 'edit other authors blog entries'), ['id' => $theirs->id()])['id'])->toBe($theirs->id());
});

it('reports an unexposed entry as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    config(['statamic.mcp.resources.collections' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesPreview::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);

    expect(mintedPreviewTokens())->toBeEmpty();
});

it('rejects a site that does not match the entry id', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(Entry::make()->collection('blog')->locale('de')->slug('hallo')->data(['title' => 'Hallo']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesPreview::class, ['id' => $entry->id(), 'site' => 'en'])
        ->assertHasErrors(["entry '{$entry->id()}' belongs to site 'de', not 'en' — pass the matching localization id instead (or omit site). Localizations: de => {$entry->id()}"]);
});

it("requires 'access de site' to preview a German entry", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(Entry::make()->collection('blog')->locale('de')->slug('hallo')->data(['title' => 'Hallo']))->save();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesPreview::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(mintedPreviewTokens())->toBeEmpty();
});

it('previews the staged working copy, not the live entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $entry = makePreviewableLive();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Staged Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    $url = previewPayload($user, ['id' => $entry->id()])['url'];

    expect(Entry::find($entry->id())->get('title'))->toBe('Live Title');

    // Before the preview request: its token swaps the item into the entry
    // repository for the rest of this test process.
    $this->get('/blog/live-post')->assertOk()->assertSee('<h1>Live Title</h1>', false);

    $this->get($url)->assertOk()->assertSee('<h1>Staged Title</h1>', false);
});

it('follows a staged slug change to the working copy url', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $entry = makePreviewableLive();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Renamed'], 'slug' => 'renamed'])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    $url = previewPayload($user, ['id' => $entry->id()])['url'];

    // Asked of the instance already in hand: Entry::find() would re-memoize the live URI.
    expect($url)->toStartWith('http://localhost/blog/renamed?live-preview=')
        ->and($entry->url())->toBe('/blog/live-post');

    $this->get($url)->assertOk()->assertSee('<h1>Renamed</h1>', false);
});

it('defaults to the first preview target and builds the url from the requested one', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::findByHandle('blog')->previewTargets([
        ['label' => 'Entry', 'format' => '{permalink}', 'refresh' => true],
        ['label' => 'Headless', 'format' => 'https://frontend.test/preview?uri={uri}', 'refresh' => true],
    ])->save();

    $entry = makePreviewableDraft();
    $user = Fixtures::makeUser('edit blog entries');

    $default = previewPayload($user, ['id' => $entry->id()]);

    expect($default['target'])->toBe('Entry')
        ->and($default['targets'])->toBe(['Entry', 'Headless'])
        ->and($default['url'])->toStartWith('http://localhost/blog/draft-post?live-preview=');

    $headless = previewPayload($user, ['id' => $entry->id(), 'target' => 'Headless']);

    expect($headless['target'])->toBe('Headless')
        ->and($headless['url'])->toMatch('#^https://frontend\.test/preview\?uri=/blog/draft-post&live-preview=[A-Za-z0-9]{16}&token=[A-Za-z0-9]{40}$#');
});

it('rejects an unknown preview target, naming the available ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesPreview::class, ['id' => $entry->id(), 'target' => 'Print'])
        ->assertHasErrors(["preview target 'Print' not found — available: Entry"]);

    expect(mintedPreviewTokens())->toBeEmpty();
});

it('refuses an entry whose collection has no route', function () {
    Fixtures::site();

    Collection::make('snippets')->title('Snippets')->save();

    $entry = tap(Entry::make()->collection('snippets')->slug('footer')->data(['title' => 'Footer']))->save();

    Server::actingAs(Fixtures::makeUser('edit snippets entries'))
        ->tool(EntriesPreview::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' cannot be previewed — collection 'snippets' has no route for site 'en', so its entries have no page"]);

    expect(mintedPreviewTokens())->toBeEmpty();
});

it('stops working an hour after the call', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));

    $entry = makePreviewableDraft();

    $payload = previewPayload(Fixtures::makeUser('edit blog entries'), ['id' => $entry->id()]);

    expect($payload['expires_at'])->toBe('2026-09-24T13:00:00+00:00');

    $this->travelTo(Carbon::parse('2026-09-24 12:59:00'));
    $this->get($payload['url'])->assertOk();

    $this->travelTo(Carbon::parse('2026-09-24 13:01:00'));
    $this->get($payload['url'])->assertNotFound();

    expect(Token::find(previewToken($payload['url'])))->toBeNull();
});

it('stays available in read_only mode, since it changes no content', function () {
    config(['statamic.mcp.read_only' => true]);

    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makePreviewableDraft();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesPreview::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"target":"Entry"');
});
