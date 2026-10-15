<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Laravel\Mcp\Server\Testing\TestResponse;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;

function articleNodes(): array
{
    return [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Opening hours']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'The shop opens at '],
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'nine'],
            ['type' => 'text', 'text' => ' each day, coffee included.'],
        ]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Free parking in the courtyard']]]]],
        ]],
        ['type' => 'set', 'attrs' => ['id' => 'call0001', 'values' => ['type' => 'callout', 'text' => 'Call each day ahead']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Call each day ahead.']]],
    ];
}

function makeArticle(mixed $body = null): Statamic\Contracts\Entries\Entry
{
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    return tap(Entry::make()->collection('landing')->slug('hours')->data([
        'title' => 'Hours',
        'body' => $body ?? articleNodes(),
        'page_builder' => [['id' => 'text0001', 'type' => 'section_text_block', 'enabled' => true, 'text' => 'First paragraph text']],
    ]))->save();
}

function updateBody(Statamic\Contracts\Entries\Entry $entry, array $edit): TestResponse
{
    return Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['body' => $edit]]);
}

it('replaces one piece of Bard text, leaving every other node as stored', function () {
    $entry = makeArticle();

    updateBody($entry, ['find' => 'coffee included', 'replace' => 'coffee and cake included'])->assertOk();

    $nodes = articleNodes();
    $nodes[1]['content'][2]['text'] = ' each day, coffee and cake included.';

    expect(Entry::find($entry->id())->get('body'))->toBe($nodes);
});

it('keeps the marks of the formatting run it edits', function () {
    $entry = makeArticle();

    updateBody($entry, ['find' => 'nine', 'replace' => 'eight'])->assertOk();

    expect(Entry::find($entry->id())->get('body')[1]['content'][1])->toBe(['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'eight']);
});

it('finds text inside nested nodes such as list items', function () {
    $entry = makeArticle();

    updateBody($entry, ['find' => 'courtyard', 'replace' => 'back yard'])->assertOk();

    expect(Entry::find($entry->id())->get('body')[2]['content'][0]['content'][0]['content'][0]['text'])->toBe('Free parking in the back yard');
});

it('refuses text that crosses formatting, matches nothing, or matches more than once', function () {
    $entry = makeArticle();

    updateBody($entry, ['find' => 'at nine', 'replace' => 'x'])
        ->assertHasErrors(['find crosses formatting such as bold, a link, or a line break in body: …The shop opens at nine each day, coffee included.…']);

    updateBody($entry, ['find' => 'CALL EACH', 'replace' => 'x'])
        ->assertHasErrors(['find matches nothing in body — it must match the stored text exactly; similar: …Call each day ahead.…']);

    updateBody($entry, ['find' => 'each day', 'replace' => 'x'])
        ->assertHasErrors(['find matches 2 times in body — add words around it so it matches once: …The shop opens at nine each day, coffee included.… | …Call each day ahead.…']);

    expect(Entry::find($entry->id())->get('body'))->toBe(articleNodes());
});

it('edits a textarea inside a page builder set addressed by id', function () {
    $entry = makeArticle();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => ['text0001' => ['text' => ['find' => 'First', 'replace' => 'Opening']]]]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('page_builder')[0]['text'])->toBe('Opening paragraph text');
});

it('edits a Bard value stored as HTML as a string', function () {
    $entry = makeArticle('<p>Hello world</p>');

    updateBody($entry, ['find' => 'world', 'replace' => 'there'])->assertOk();

    expect(Entry::find($entry->id())->get('body')[0]['content'][0]['text'])->toBe('Hello there');
});

it('refuses an edit object of another shape', function () {
    $entry = makeArticle();

    updateBody($entry, ['find' => 'coffee', 'with' => 'tea'])
        ->assertHasErrors(['body takes its new value, or {"find": "...", "replace": "..."} to change one piece of its text']);

    updateBody($entry, ['find' => '', 'replace' => 'tea'])
        ->assertHasErrors(['body takes its new value, or {"find": "...", "replace": "..."}']);
});

it('changes one piece of a text field such as the title', function () {
    $entry = makeArticle();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => ['find' => 'Hours', 'replace' => 'Opening hours']]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('title'))->toBe('Opening hours');
});

it('refuses find and replace where it cannot reach, inside a Group or a new set', function () {
    $entry = makeArticle();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['seo' => ['meta_title' => ['find' => 'a', 'replace' => 'b']]]])
        ->assertHasErrors(['seo.meta_title takes {"find": "...", "replace": "..."} only at the top level or in a set addressed by id — send its new value']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['page_builder' => [['type' => 'section_text_block', 'text' => ['find' => 'a', 'replace' => 'b']]]]])
        ->assertHasErrors(['page_builder.0.text takes {"find": "...", "replace": "..."} only at the top level or in a set addressed by id']);

    expect(Entry::find($entry->id())->get('page_builder')[0]['text'])->toBe('First paragraph text');
});

it('refuses text that matches once inside a formatting run but again across formatting', function () {
    $entry = makeArticle([
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Phone: '],
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => '030'],
            ['type' => 'text', 'text' => ' 1234'],
        ]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Fax: 030 1234']]],
    ]);

    updateBody($entry, ['find' => '030 1234', 'replace' => '030 9999'])
        ->assertHasErrors(['find matches 2 times in body']);

    expect(Entry::find($entry->id())->get('body')[1]['content'][0]['text'])->toBe('Fax: 030 1234');
});

it('finds text in an inline Bard field, stored as top-level text nodes', function () {
    $entry = makeArticle();

    Blueprint::find('collections.landing.landing_page')->ensureField('teaser', ['type' => 'bard', 'inline' => true])->save();
    $entry->set('teaser', [['type' => 'text', 'text' => 'Open every day']])->save();

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['teaser' => ['find' => 'every day', 'replace' => 'daily']]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('teaser'))->toBe([['type' => 'text', 'text' => 'Open daily']]);
});

it('quotes similar text when only quotes, dashes, or spaces differ, and names text inside a Bard set', function () {
    $entry = makeArticle([
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "We\u{2019}re open \u{2013} come in."]]],
        ['type' => 'set', 'attrs' => ['id' => 'call0001', 'values' => ['type' => 'callout', 'text' => 'Call ahead please']]],
    ]);

    updateBody($entry, ['find' => "We're open - come", 'replace' => 'x'])
        ->assertHasErrors(["find matches nothing in body — it must match the stored text exactly; similar: …We\u{2019}re open \u{2013} come in.…"]);

    updateBody($entry, ['find' => 'Call ahead', 'replace' => 'Ring ahead'])
        ->assertHasErrors(['find matches text inside a Bard set in body, which find and replace cannot reach — send the whole field']);
});

it('keeps the shape error for any other object sent to a Bard field', function () {
    $entry = makeArticle();

    updateBody($entry, ['type' => 'doc', 'content' => []])
        ->assertHasErrors(['body must be a list of ProseMirror nodes, or an HTML string']);
});

it('no longer marks the update tools idempotent, since inserting text or a set twice changes the result twice', function () {
    expect((new EntriesUpdate)->annotations())->not->toHaveKey('idempotentHint')
        ->and((new TermsUpdate)->annotations())->not->toHaveKey('idempotentHint')
        ->and((new GlobalsUpdate)->annotations())->not->toHaveKey('idempotentHint');
});
