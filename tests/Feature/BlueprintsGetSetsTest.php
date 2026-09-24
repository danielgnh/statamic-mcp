<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;

beforeEach(function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();
});

it('lists the sets of a replicator with their group, instructions, and fields', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => [
            'type' => 'replicator',
            'sets' => [
                'headers' => [
                    'display' => 'Headers',
                    'sets' => [
                        'hero' => [
                            'display' => 'Hero',
                            'instructions' => 'First block on landing pages, never twice.',
                            'fields' => [
                                ['handle' => 'heading', 'field' => ['type' => 'text', 'validate' => 'required', 'instructions' => 'Under 8 words.']],
                            ],
                        ],
                    ],
                ],
                'content' => [
                    'sets' => [
                        'logo_wall' => [
                            'display' => 'Logo Wall',
                            'fields' => [['handle' => 'logos', 'field' => ['type' => 'text']]],
                        ],
                    ],
                ],
            ],
        ],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"handle":"page_builder","type":"replicator","required":false,"rules":["array","nullable"],"sets":[')
        ->assertSee('{"handle":"hero","display":"Hero","group":"Headers","instructions":"First block on landing pages, never twice.","fields":[{"handle":"heading","type":"text","required":true,"rules":["required"],"instructions":"Under 8 words."}]}')
        // no instructions on the set, and a group without a display name falls back to its handle
        ->assertSee('{"handle":"logo_wall","display":"Logo Wall","group":"Content","fields":[{"handle":"logos","type":"text","required":false,"rules":["nullable"]}]}');
});

it('reads the legacy ungrouped sets format without a group', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => [
            'type' => 'replicator',
            'sets' => [
                'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
            ],
        ],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"sets":[{"handle":"quote","display":"Quote","fields":[{"handle":"text","type":"textarea","required":false,"rules":["nullable"]}]}]');
});

it('lists the sets of a bard field and leaves a bard without sets alone', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'body' => [
            'type' => 'bard',
            'sets' => [
                'main' => ['sets' => [
                    'callout' => ['display' => 'Callout', 'instructions' => 'At most one per article.', 'fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]],
                ]],
            ],
        ],
        'summary' => ['type' => 'bard'],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"handle":"body","type":"bard","required":false,"rules":["nullable"],"sets":[{"handle":"callout","display":"Callout","group":"Main","instructions":"At most one per article."')
        ->assertSee('{"handle":"summary","type":"bard","required":false,"rules":["nullable"]}');
});

it('resolves fieldset imports inside a set and describes nested sets', function () {
    Fieldset::make('button')->setContents(['fields' => [
        ['handle' => 'label', 'field' => ['type' => 'text', 'instructions' => 'Two or three words.']],
    ]])->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => [
            'type' => 'replicator',
            'sets' => [
                'main' => ['sets' => [
                    'cta' => ['display' => 'CTA', 'fields' => [['import' => 'button']]],
                    'columns' => ['display' => 'Columns', 'fields' => [
                        ['handle' => 'items', 'field' => ['type' => 'replicator', 'sets' => [
                            'main' => ['sets' => [
                                'text' => ['display' => 'Text', 'instructions' => 'Keep it under 40 words.'],
                            ]],
                        ]]],
                    ]],
                ]],
            ],
        ],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('{"handle":"cta","display":"CTA","group":"Main","fields":[{"handle":"label","type":"text","required":false,"rules":["nullable"],"instructions":"Two or three words."}]}')
        ->assertSee('"sets":[{"handle":"text","display":"Text","group":"Main","instructions":"Keep it under 40 words.","fields":[]}]');
});

it('marks hidden sets so agents keep them for existing content only', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => [
            'type' => 'replicator',
            'sets' => [
                'main' => ['sets' => [
                    'old_banner' => ['display' => 'Old Banner', 'hide' => true],
                ]],
            ],
        ],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('{"handle":"old_banner","display":"Old Banner","group":"Main","hidden":true,"fields":[]}');
});
