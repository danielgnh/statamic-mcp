<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesPublish;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
});

function makeNewsStory(string $date, bool $published = false): EntryContract
{
    return tap(
        Entry::make()->collection('news')->slug('launch')->data(['title' => 'Launch'])->date(Carbon::parse($date))->published($published)
    )->save();
}

it('reports a future-dated publish as scheduled, not live', function () {
    Fixtures::site();
    Fixtures::news();

    $entry = makeNewsStory('2026-10-06T09:00:00+02:00');

    Server::actingAs(Fixtures::makeUser('publish news entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"scheduled"')
        ->assertSee('"result":"scheduled — published, but not live until its date"')
        ->assertSee('"date":"2026-10-06T07:00:00+00:00"');

    expect(Entry::find($entry->id())->published())->toBeTrue();
});

it('reports a future-dated publish as live where the collection shows future dates', function () {
    Fixtures::site();
    Fixtures::news(future: 'public');

    $entry = makeNewsStory('2026-10-06T09:00:00+02:00');

    Server::actingAs(Fixtures::makeUser('publish news entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"published"')
        ->assertSee('"result":"published"');
});

it('reports a past-dated publish as expired where past dates are private', function () {
    Fixtures::site();
    Fixtures::news(past: 'private');

    $entry = makeNewsStory('2026-09-01T09:00:00+00:00');

    Server::actingAs(Fixtures::makeUser('publish news entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"expired"')
        ->assertSee('"result":"expired — published, but its date has passed, not live"');
});

it('names the scheduled state in the no-op result', function () {
    Fixtures::site();
    Fixtures::news();

    $entry = makeNewsStory('2026-10-06T09:00:00+02:00', published: true);

    Server::actingAs(Fixtures::makeUser('publish news entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"scheduled"')
        ->assertSee('no-op — already scheduled, nothing staged');
});

it('reports a promoted working copy with a future date as scheduled', function () {
    Fixtures::site();
    Fixtures::news();
    Fixtures::revisions('news');

    $entry = makeNewsStory('2026-09-01T09:00:00+00:00', published: true);
    $user = Fixtures::makeUser('publish news entries');

    (clone $entry)->date(Carbon::parse('2026-10-06T09:00:00+02:00'))->makeWorkingCopy()->user($user)->save();

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"scheduled"')
        ->assertSee('"result":"scheduled — published, but not live until its date"');
});

it('reports a published entry re-dated into the future as scheduled', function () {
    Fixtures::site();
    Fixtures::news();

    $entry = makeNewsStory('2026-09-01T09:00:00+00:00', published: true);

    Server::actingAs(Fixtures::makeUser('edit news entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [], 'date' => '2026-10-06T09:00:00+02:00'])
        ->assertOk()
        ->assertSee('"status":"scheduled"')
        ->assertSee('"result":"scheduled — published, but not live until its date"');
});

it('reads a time without an offset in app.timezone and honors an explicit offset', function () {
    Fixtures::site();
    Fixtures::news();

    config(['app.timezone' => 'Europe/Berlin']);

    $user = Fixtures::makeUser('create news entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'news', 'data' => ['title' => 'Naive'], 'date' => '2026-10-06 09:00'])
        ->assertOk()
        ->assertSee('"date":"2026-10-06T07:00:00+00:00"');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'news', 'data' => ['title' => 'Offset'], 'date' => '2026-10-06T09:00:00-04:00'])
        ->assertOk()
        ->assertSee('"date":"2026-10-06T13:00:00+00:00"');
});

it('refuses a date on a localization that inherits it from its origin', function () {
    Fixtures::multisite();
    Fixtures::news();

    $origin = makeNewsStory('2026-09-01T09:00:00+00:00', published: true);
    $localization = tap($origin->makeLocalization('de')->published(true))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => [], 'date' => '2026-10-06T09:00:00+02:00'])
        ->assertHasErrors(["this localization inherits its date from entry '{$origin->id()}' — change the date there, or omit date"]);

    expect(Entry::find($localization->id())->date()->toIso8601String())->toBe('2026-09-01T09:00:00+00:00');
});

it("keeps the author rule when scheduling someone else's entry", function () {
    Fixtures::site();
    Fixtures::news();
    Fixtures::authors('news');

    $entry = tap(makeNewsStory('2026-09-01T09:00:00+00:00')->set('author', Fixtures::makeUser()->id()))->save();
    $user = Fixtures::makeUser('edit news entries', 'publish news entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [], 'date' => '2026-10-06T09:00:00+02:00'])
        ->assertHasErrors(["requires 'edit other authors news entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'publish other authors news entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    $editor = Fixtures::makeUser('edit news entries', 'edit other authors news entries', 'publish news entries', 'publish other authors news entries');

    Server::actingAs($editor)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [], 'date' => '2026-10-06T09:00:00+02:00'])
        ->assertOk();

    Server::actingAs($editor)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"scheduled"')
        ->assertSee('"result":"scheduled — published, but not live until its date"');
});
