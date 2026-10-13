<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

function makePageWithBlocks(array $overrides = []): Statamic\Contracts\Entries\Entry
{
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    return tap(Entry::make()->collection('landing')->slug('home')->data([
        'title' => 'Home',
        'page_builder' => [
            ['id' => 'hero0001', 'type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg'],
            ['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'First'],
            ['id' => 'text0002', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'Second'],
        ],
        'facts' => [['id' => 'row00001', 'label' => 'Rooms'], ['id' => 'row00002', 'label' => 'Baths']],
        ...$overrides,
    ]))->save();
}

/**
 * A page builder whose cards set nests a grid, in a collection of every site
 * with the field localizable — the shape of a real site's blocks.
 */
function makeSectionsCollection(): void
{
    tap(
        Collection::make('sections')
            ->title('Sections')
            ->sites(Site::all()->map->handle()->values()->all())
            ->routes('/sections/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required', 'localizable' => true],
        'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => [
            'main' => ['sets' => [
                'hero' => ['fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text', 'validate' => 'required']],
                ]],
                'cards' => ['fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text']],
                    ]]],
                ]],
                'quote' => ['fields' => [
                    ['handle' => 'eyebrow', 'field' => ['type' => 'text']],
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                ]],
            ]],
        ]],
    ])->setHandle('section')->setNamespace('collections.sections')->save();
}

function sectionBlocks(): array
{
    return [
        ['id' => 'hero0001', 'type' => 'hero', 'enabled' => true, 'heading' => 'Welcome'],
        ['id' => 'card0001', 'type' => 'cards', 'enabled' => true, 'heading' => 'Services', 'items' => [
            ['id' => 'item0001', 'title' => 'Buy'],
            ['id' => 'item0002', 'title' => 'Sell'],
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function entriesGetPayload(UserContract $user, array $arguments): array
{
    test()->actingAs($user);

    $response = (new EntriesGet)->handle(new Request($arguments));

    Auth::forgetGuards();

    expect($response->isError())->toBeFalse();

    return json_decode((string) $response->content(), true);
}

it('merges one field of one set, leaving the other sets, their order, and their ids untouched', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0002' => ['text' => 'Second, edited']]]])
        ->assertOk()
        ->assertSee('"result":"published"');

    $sets = Entry::find($entry->id())->get('page_builder');

    expect(array_column($sets, 'id'))->toBe(['hero0001', 'text0001', 'text0002'])
        ->and($sets[0])->toBe(['id' => 'hero0001', 'type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg'])
        ->and($sets[1]['text'])->toBe('First')
        ->and($sets[2])->toBe(['id' => 'text0002', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'Second, edited']);
});

it('removes a set with null', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => null]]])
        ->assertOk();

    expect(array_column(Entry::find($entry->id())->get('page_builder'), 'id'))->toBe(['hero0001', 'text0002']);
});

it('rejects an unknown set id, listing the existing sets with their types', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['nope0000' => ['text' => 'x']]]])
        ->assertHasErrors(["no set with id 'nope0000' in page_builder — existing sets: hero0001 (section_hero), text0001 (section_text_block), text0002 (section_text_block)"]);

    expect(Entry::find($entry->id())->get('page_builder'))->toHaveCount(3);
});

it('merges into a grid row by id', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['facts' => ['row00002' => ['label' => 'Bathrooms']]]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('facts'))->toBe([
        ['id' => 'row00001', 'label' => 'Rooms'],
        ['id' => 'row00002', 'label' => 'Bathrooms'],
    ]);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['facts' => ['nope0000' => ['label' => 'x']]]])
        ->assertHasErrors(["no row with id 'nope0000' in facts — existing rows: row00001, row00002"]);
});

it('validates a merged set against its blueprint', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['hero0001' => ['heading' => null]]]])
        ->assertHasErrors(['validation failed: {"page_builder.hero0001.heading"']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['hero0001' => ['headline' => 'Typo']]]])
        ->assertHasErrors(['unknown field headline in page_builder.hero0001', "did you mean 'heading'"]);

    expect(Entry::find($entry->id())->get('page_builder')[0]['heading'])->toBe('Welcome');
});

it('refuses a set patch that is not an object, or carries another id', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => 'First']]])
        ->assertHasErrors(['page_builder.text0001 must be an object of field values to merge into that set, or null to remove it']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['id' => 'text0009', 'text' => 'x']]]])
        ->assertHasErrors(['page_builder.text0001 carries id "text0009" — a set\'s id is its key and never changes']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['id' => ['x']]]]])
        ->assertHasErrors(['page_builder.text0001 carries id ["x"]']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['type' => 'section_hero', 'heading' => 'x']]]])
        ->assertHasErrors(["page_builder.text0001 is a section_text_block set — changing a set's type takes the complete list"]);

    // The set's own id inside the patch is what entries_get hands back: fine.
    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['id' => 'text0001', 'text' => 'Kept id']]]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('page_builder')[1]['text'])->toBe('Kept id');
});

it('is a no-op when the merged set equals the stored one', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Event::fake([EntrySaved::class]);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['text' => 'First']]]])
        ->assertOk()
        ->assertSee('no-op');

    Event::assertNotDispatched(EntrySaved::class);
});

it('still replaces the field wholesale when given a list, refuses an empty one, and clears it with null', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => [['type' => 'section_text_block', 'text' => 'Only']]]])
        ->assertOk();

    $sets = Entry::find($entry->id())->get('page_builder');

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['text'])->toBe('Only')
        ->and($sets[0]['id'])->toBeString()->toHaveLength(8);

    // PHP decodes {} and [] alike, and {} under the by-id contract must never wipe a page.
    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => []]])
        ->assertHasErrors(['page_builder is empty — send null to clear the field, its complete list to replace it, or an object keyed by set id to merge']);

    expect(Entry::find($entry->id())->get('page_builder'))->toHaveCount(1);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => null]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('page_builder'))->toBeNull();
});

it('merges into a grid nested inside a set', function () {
    Fixtures::site();
    makeSectionsCollection();

    $entry = tap(Entry::make()->collection('sections')->slug('home')->data(['title' => 'Home', 'blocks' => sectionBlocks()]))->save();

    Server::actingAs(Fixtures::makeUser('edit sections entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['blocks' => ['card0001' => ['items' => ['item0002' => ['title' => 'Rent']]]]]])
        ->assertOk();

    $cards = Entry::find($entry->id())->get('blocks')[1];

    expect($cards['heading'])->toBe('Services')
        ->and($cards['items'])->toBe([['id' => 'item0001', 'title' => 'Buy'], ['id' => 'item0002', 'title' => 'Rent']]);

    Server::actingAs(Fixtures::makeUser('edit sections entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['blocks' => ['card0001' => ['items' => ['nope0000' => ['title' => 'x']]]]]])
        ->assertHasErrors(["no row with id 'nope0000' in blocks.card0001.items — existing rows: item0001, item0002"]);

    Server::actingAs(Fixtures::makeUser('edit sections entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['blocks' => ['card0001' => ['items' => []]]]])
        ->assertHasErrors(['blocks.card0001.items is empty — send null to clear the field']);

    expect(Entry::find($entry->id())->get('blocks')[1]['items'])->toHaveCount(2);
});

it('merges into the staged working copy, never the live entry', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();
    Fixtures::revisions('landing');

    $user = Fixtures::makeUser('edit landing entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['hero0001' => ['heading' => 'Staged']]]])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['text' => 'Also staged']]]])
        ->assertOk()
        ->assertSee('working copy amended — live entry unchanged');

    $staged = Entry::find($entry->id())->workingCopy()->attributes()['data']['page_builder'];

    expect($staged[0]['heading'])->toBe('Staged')
        ->and($staged[1]['text'])->toBe('Also staged')
        ->and($staged[2]['text'])->toBe('Second');

    $live = Entry::find($entry->id())->get('page_builder');

    expect($live[0]['heading'])->toBe('Welcome')
        ->and($live[1]['text'])->toBe('First');
});

it('merges into the inherited sets on a localization, which then overrides the field', function () {
    Fixtures::multisite();
    makeSectionsCollection();

    $origin = tap(Entry::make()->collection('sections')->slug('home')->locale('en')->data(['title' => 'Home', 'blocks' => sectionBlocks()]))->save();
    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Start']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['blocks' => ['hero0001' => ['heading' => 'Willkommen']]]])
        ->assertOk();

    $german = Entry::find($localization->id())->get('blocks');

    expect(array_column($german, 'id'))->toBe(['hero0001', 'card0001'])
        ->and($german[0]['heading'])->toBe('Willkommen')
        ->and($german[1]['items'])->toHaveCount(2)
        ->and(Entry::find($origin->id())->get('blocks')[0]['heading'])->toBe('Welcome');
});

it('indexes a large replicator field by set id, with a preview of each set', function () {
    Fixtures::site();

    $long = str_repeat('lorem ipsum ', 200).'END_OF_TEXT';

    $entry = makePageWithBlocks(['page_builder' => [
        ['id' => 'hero0001', 'type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg'],
        ['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => $long],
        ['id' => 'text0002', 'type' => 'section_text_block', 'enabled' => false, 'text' => $long],
    ]]);

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"__preview":"3 sets"')
        ->assertSee('"truncated":true')
        ->assertSee('{"id":"hero0001","type":"section_hero","preview":"Welcome"}')
        ->assertSee('{"id":"text0001","type":"section_text_block","preview":"lorem ipsum lorem ipsum')
        ->assertSee('{"id":"text0002","type":"section_text_block","enabled":false,"preview":"lorem')
        ->assertSee('page_builder.<id>')
        ->assertDontSee('END_OF_TEXT')
        // the small grid stays inline
        ->assertSee('"facts":[{"id":"row00001","label":"Rooms"}');

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder']])
        ->assertOk()
        ->assertSee('END_OF_TEXT')
        ->assertDontSee('__preview');
});

it('keeps a small replicator field inline', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"page_builder":[{"id":"hero0001"')
        ->assertDontSee('__preview');
});

it('returns one set as an object keyed by its id via fields', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.text0002', 'facts.row00001']])
        ->assertOk()
        ->assertSee('"page_builder":{"text0002":{"id":"text0002","type":"section_text_block","enabled":true,"text":"Second"}}')
        ->assertSee('"facts":{"row00001":{"id":"row00001","label":"Rooms"}}')
        ->assertDontSee('text0001')
        ->assertDontSee('"title":"Home"');
});

it('round-trips a selected set into entries_update as a no-op', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    $payload = entriesGetPayload(Fixtures::makeSuper(), ['id' => $entry->id(), 'fields' => ['page_builder.hero0001']]);

    Event::fake([EntrySaved::class]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => $payload['data']])
        ->assertOk()
        ->assertSee('no-op');

    Event::assertNotDispatched(EntrySaved::class);
});

it('rejects a selector of an unknown set, listing the existing sets', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.nope0000']])
        ->assertHasErrors(["no set with id 'nope0000' in page_builder — existing sets: hero0001 (section_hero), text0001 (section_text_block), text0002 (section_text_block)"]);
});

it('rejects a set selector on a field without sets, and a field mixed with its own set', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['title.abc']])
        ->assertHasErrors(['title.abc selects a set by id, which only Replicator and Grid fields have — title is a text field']);

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['body.abc']])
        ->assertHasErrors(['body.abc selects a set by id, but Bard sets live inside the Bard value and can\'t be addressed that way — fetch the whole field with fields: ["body"]']);

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder', 'page_builder.text0001']])
        ->assertHasErrors(['fields lists both page_builder and page_builder.text0001']);

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['nope.abc']])
        ->assertHasErrors(['unknown field nope']);
});

it('keeps sets the blueprint no longer describes, as a Control Panel save does', function () {
    Fixtures::site();

    $entry = makePageWithBlocks(['page_builder' => [
        ['id' => 'hero0001', 'type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg', 'old_subtitle' => 'Renamed since'],
        ['id' => 'gone0001', 'type' => 'section_removed', 'enabled' => true, 'text' => 'Set type removed since'],
        ['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'First'],
    ]]);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['text' => 'Edited']]]])
        ->assertOk();

    $sets = Entry::find($entry->id())->get('page_builder');

    expect($sets[0]['old_subtitle'])->toBe('Renamed since')
        ->and($sets[1]['type'])->toBe('section_removed')
        ->and($sets[2]['text'])->toBe('Edited');

    // A set fetched by id round-trips with its stale key, unchanged.
    $payload = entriesGetPayload(Fixtures::makeSuper(), ['id' => $entry->id(), 'fields' => ['page_builder.hero0001']]);

    expect($payload['data']['page_builder']['hero0001']['old_subtitle'])->toBe('Renamed since');

    $payload['data']['page_builder']['hero0001']['heading'] = 'Welcome back';

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => $payload['data']])
        ->assertOk();

    expect(Entry::find($entry->id())->get('page_builder')[0])->toMatchArray(['heading' => 'Welcome back', 'old_subtitle' => 'Renamed since']);

    // Changing the stale key, or a set of a removed type, needs the complete list.
    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['hero0001' => ['old_subtitle' => 'x']]]])
        ->assertHasErrors(["page_builder.hero0001 stores old_subtitle, which its blueprint no longer defines — it can't be changed by id; send the complete list to replace or drop it"]);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['gone0001' => ['text' => 'x']]]])
        ->assertHasErrors(["page_builder.gone0001 is a set of type 'section_removed', which the blueprint no longer defines — send the complete list to replace or drop it"]);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['gone0001' => null]]])
        ->assertOk();

    expect(array_column(Entry::find($entry->id())->get('page_builder'), 'id'))->toBe(['hero0001', 'text0001']);
});

it('reports a typo on a localization as an unknown field, not as unlocalizable', function () {
    Fixtures::multisite();
    makeSectionsCollection();

    $origin = tap(Entry::make()->collection('sections')->slug('home')->locale('en')->data(['title' => 'Home', 'blocks' => sectionBlocks()]))->save();
    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Start']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['titel' => 'Hallo']])
        ->assertHasErrors(['unknown field titel', "did you mean 'title'"]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['published' => true]])
        ->assertHasErrors(['field published is reserved']);
});

it('refuses ids it cannot address, shared, blank, or 0, and takes digit-only ids', function () {
    Fixtures::site();

    $entry = makePageWithBlocks(['page_builder' => [
        ['id' => 'dupe0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'A'],
        ['id' => 'dupe0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'B'],
        ['id' => '90385650', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'C'],
        ['id' => '0', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'D'],
        ['type' => 'section_text_block', 'enabled' => true, 'text' => 'No id yet'],
    ]]);

    $user = Fixtures::makeUser('edit landing entries', 'view landing entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['dupe0001' => ['text' => 'x']]]])
        ->assertHasErrors(["id 'dupe0001' is shared by 2 sets in page_builder — fetch or send the whole field"]);

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.dupe0001']])
        ->assertHasErrors(["id 'dupe0001' is shared by 2 sets"]);

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.0']])
        ->assertHasErrors(["the set id 0 can't be addressed, PHP reads it as a list index — fetch or send the whole page_builder"]);

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.']])
        ->assertHasErrors(['pass page_builder.<id> with a set id — the index of page_builder lists them']);

    // Hex ids from other tooling are digits only about 2% of the time; they stay addressable.
    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.90385650']])
        ->assertOk()
        ->assertSee('"page_builder":{"90385650":{"id":"90385650"');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['90385650' => ['text' => 'C, edited']]]])
        ->assertOk();

    expect(array_column(Entry::find($entry->id())->get('page_builder'), 'text'))->toBe(['A', 'B', 'C, edited', 'D', 'No id yet']);
});

it('requires enabled to be a boolean, by id and in a list', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['enabled' => 'no']]]])
        ->assertHasErrors(['page_builder.text0001.enabled must be true or false']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => [['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => 'no', 'text' => 'First']]]])
        ->assertHasErrors(['page_builder.text0001.enabled must be true or false']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['enabled' => false]]]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('page_builder')[1]['enabled'])->toBeFalse();
});

it('names sets by id in list errors too, when they carry one', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => [
            ['id' => 'hero0001', 'type' => 'section_hero', 'headline' => 'x'],
        ]]])
        ->assertHasErrors(['unknown field headline in page_builder.hero0001', "did you mean 'heading'"]);
});

it('prefers a title-like field for the preview over the first text field', function () {
    Fixtures::site();
    makeSectionsCollection();

    $entry = tap(Entry::make()->collection('sections')->slug('home')->data(['title' => 'Home', 'blocks' => [
        ['id' => 'quot0001', 'type' => 'quote', 'enabled' => true, 'eyebrow' => 'Kunden', 'heading' => 'Was Kunden sagen'],
        ['id' => 'hero0001', 'type' => 'hero', 'enabled' => true, 'heading' => str_repeat('Welcome ', 600)],
    ]]))->save();

    Server::actingAs(Fixtures::makeUser('view sections entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('{"id":"quot0001","type":"quote","preview":"Was Kunden sagen"}');
});

it('replaces a group inside a set whole, as documented', function () {
    Fixtures::site();
    Fixtures::pages();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'blocks' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'cta' => ['fields' => [
                ['handle' => 'button', 'field' => ['type' => 'group', 'fields' => [
                    ['handle' => 'label', 'field' => ['type' => 'text']],
                    ['handle' => 'url', 'field' => ['type' => 'text']],
                ]]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    $entry = tap(Entry::make()->collection('pages')->slug('home')->data(['title' => 'Home', 'blocks' => [
        ['id' => 'ctaa0001', 'type' => 'cta', 'enabled' => true, 'button' => ['label' => 'Go', 'url' => '/go']],
    ]]))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['blocks' => ['ctaa0001' => ['button' => ['label' => 'Go now']]]]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('blocks')[0]['button'])->toBe(['label' => 'Go now']);
});

it('refuses the index sent back, pointing at the by-id patch', function () {
    Fixtures::site();

    $entry = makePageWithBlocks();

    $index = ['__preview' => '3 sets', 'truncated' => true, 'sets' => [['id' => 'hero0001', 'type' => 'section_hero', 'preview' => 'Welcome']], 'note' => 'x'];

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => $index]])
        ->assertHasErrors(['field page_builder is the index of its sets from entries_get, not raw content — fetch one set with fields: ["page_builder.<id>"]']);
});

it('indexes a large replicator in augmented format too, and a large grid never', function () {
    Fixtures::site();

    $long = str_repeat('lorem ipsum ', 400);

    $entry = makePageWithBlocks([
        'page_builder' => [['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => $long]],
        'facts' => array_map(fn (int $i) => ['id' => sprintf('row%05d', $i), 'label' => "Fact {$i} ".str_repeat('x', 60)], range(1, 80)),
    ]);

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"__preview":"1 set"')
        ->assertSee('{"id":"text0001","type":"section_text_block","preview":"lorem')
        ->assertSee('"facts":[{"label":"Fact 1 ')
        ->assertSee('"id":"row00080"');
});

it('addresses sets through a custom row id handle', function () {
    config(['statamic.system.row_id_handle' => 'uid']);

    Fixtures::site();

    $entry = makePageWithBlocks(['page_builder' => [
        ['uid' => 'hero0001', 'type' => 'section_hero', 'enabled' => true, 'heading' => 'Welcome', 'image' => 'hero.jpg'],
        ['uid' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'First'],
    ]]);

    $user = Fixtures::makeUser('edit landing entries', 'view landing entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['page_builder.text0001']])
        ->assertOk()
        ->assertSee('"page_builder":{"text0001":{"uid":"text0001"');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['text' => 'Edited']]]])
        ->assertOk();

    $sets = Entry::find($entry->id())->get('page_builder');

    expect(array_column($sets, 'uid'))->toBe(['hero0001', 'text0001'])
        ->and($sets[1]['text'])->toBe('Edited');
});

it('leaves large sets by id out of entries_create, where there is nothing to merge into', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('create landing entries'))
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'slug' => 'new', 'data' => ['title' => 'New', 'page_builder' => ['abcd1234' => ['text' => 'x']]]])
        ->assertHasErrors(['page_builder must be a list of sets, each an object with a type — an object keyed by set id merges into stored sets only through an update']);
});
