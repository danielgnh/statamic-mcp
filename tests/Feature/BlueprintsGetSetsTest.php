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

it('lists the sets of a replicator with their group and instructions, and returns one with its fields', function () {
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
        // no instructions on logo_wall, and a group without a display name falls back to its handle
        ->assertSee('"handle":"page_builder","type":"replicator","required":false,"rules":["array","nullable"],"sets":[{"handle":"hero","display":"Hero","group":"Headers","instructions":"First block on landing pages, never twice."},{"handle":"logo_wall","display":"Logo Wall","group":"Content"}]}')
        ->assertDontSee('"handle":"heading"');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'hero'])
        ->assertOk()
        ->assertSee('"set":{"handle":"hero","display":"Hero","group":"Headers","instructions":"First block on landing pages, never twice.","fields":[{"handle":"heading","type":"text","required":true,"rules":["required"],"instructions":"Under 8 words."}]}');
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
        ->assertSee('"sets":[{"handle":"quote","display":"Quote"}]');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'quote'])
        ->assertOk()
        ->assertSee('"set":{"handle":"quote","display":"Quote","fields":[{"handle":"text","type":"textarea","required":false,"rules":["nullable"]}]}');
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
        ->assertSee('"handle":"body","type":"bard","required":false,"rules":["nullable"],"sets":[{"handle":"callout","display":"Callout","group":"Main","instructions":"At most one per article."}]}')
        ->assertSee('{"handle":"summary","type":"bard","required":false,"rules":["nullable"]}');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'callout'])
        ->assertOk()
        ->assertSee('"set":{"handle":"callout","display":"Callout","group":"Main","instructions":"At most one per article.","fields":[{"handle":"text","type":"text","required":false,"rules":["nullable"]}]}');
});

it('resolves fieldset imports inside a set and lists nested sets by name', function () {
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
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'cta'])
        ->assertOk()
        ->assertSee('"set":{"handle":"cta","display":"CTA","group":"Main","fields":[{"handle":"label","type":"text","required":false,"rules":["nullable"],"instructions":"Two or three words."}]}');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'columns'])
        ->assertOk()
        ->assertSee('"set":{"handle":"columns","display":"Columns","group":"Main","fields":[{"handle":"items","type":"replicator","required":false,"rules":["array","nullable"],"sets":[{"handle":"text","display":"Text","group":"Main","instructions":"Keep it under 40 words."}]}]}');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'text'])
        ->assertOk()
        ->assertSee('"set":{"handle":"text","display":"Text","group":"Main","instructions":"Keep it under 40 words.","fields":[]}');
});

it('skips a set group whose sets are empty in yaml', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => [
            'type' => 'replicator',
            'sets' => [
                'main' => ['sets' => ['hero' => ['display' => 'Hero']]],
                'later' => ['display' => 'Later', 'sets' => null],
            ],
        ],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"sets":[{"handle":"hero","display":"Hero","group":"Main"}]');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'hero'])
        ->assertOk()
        ->assertSee('"set":{"handle":"hero","display":"Hero","group":"Main","fields":[]}');
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
        ->assertSee('{"handle":"old_banner","display":"Old Banner","group":"Main","hidden":true}');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'old_banner'])
        ->assertOk()
        ->assertSee('"set":{"handle":"old_banner","display":"Old Banner","group":"Main","hidden":true,"fields":[]}');
});

it('keeps the options of fields inside a set and leaves set icons out', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'hero' => ['display' => 'Hero', 'icon' => 'home-house', 'fields' => [
                ['handle' => 'variant', 'field' => ['type' => 'select', 'options' => [['key' => 'default', 'value' => 'Default'], ['key' => 'search', 'value' => 'Search']]]],
                ['handle' => 'layout', 'field' => ['type' => 'button_group', 'options' => ['slider' => 'Slider', 'single' => 'Single']]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'hero'])
        ->assertOk()
        ->assertSee('"set":{"handle":"hero","display":"Hero","group":"Main","fields":['
            .'{"handle":"variant","type":"select","required":false,"rules":["nullable"],"options":[{"key":"default","value":"Default"},{"key":"search","value":"Search"}]},'
            .'{"handle":"layout","type":"button_group","required":false,"rules":["nullable"],"options":{"slider":"Slider","single":"Single"}}]}');
});

it('finds a set that another set nests again when their fields match', function () {
    Fieldset::make('hero')->setContents(['fields' => [
        ['handle' => 'heading', 'field' => ['type' => 'text']],
    ]])->save();

    // like a "combined" section that offers the page builder's sections again
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'hero' => ['display' => 'Hero', 'fields' => [['import' => 'hero']]],
            'combined' => ['display' => 'Combined', 'fields' => [
                ['handle' => 'sections', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                    'hero' => ['display' => 'Hero', 'fields' => [['import' => 'hero']]],
                ]]]]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'hero'])
        ->assertOk()
        ->assertSee('"set":{"handle":"hero","display":"Hero","group":"Main","fields":[{"handle":"heading","type":"text","required":false,"rules":["nullable"]}]}');
});

it('names the paths when a set handle has different fields in different places', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'link' => ['display' => 'Link', 'fields' => [['handle' => 'url', 'field' => ['type' => 'text']]]],
            'cta' => ['display' => 'CTA', 'fields' => [
                ['handle' => 'buttons', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                    'link' => ['display' => 'Link', 'fields' => [['handle' => 'label', 'field' => ['type' => 'text']]]],
                ]]]]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'link'])
        ->assertHasErrors(["set 'link' has different fields in page_builder.link, page_builder.cta.buttons.link — pass the path of the one you mean as set"]);

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'page_builder.cta.buttons.link'])
        ->assertOk()
        ->assertSee('"set":{"handle":"link","display":"Link","group":"Main","fields":[{"handle":"label","type":"text"');
});

it('finds sets inside grids and groups', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'hero' => ['type' => 'group', 'fields' => [
            ['handle' => 'buttons', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'button' => ['display' => 'Button', 'fields' => [['handle' => 'label', 'field' => ['type' => 'text']]]],
            ]]]]],
        ]],
        'rows' => ['type' => 'grid', 'fields' => [
            ['handle' => 'cells', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'cell' => ['display' => 'Cell', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
            ]]]]],
        ]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'hero.buttons.button'])
        ->assertOk()
        ->assertSee('"set":{"handle":"button","display":"Button","group":"Main","fields":[{"handle":"label","type":"text"');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'cell'])
        ->assertOk()
        ->assertSee('"set":{"handle":"cell","display":"Cell","group":"Main","fields":[{"handle":"text","type":"textarea"');
});

it('rejects an unknown set, listing the handles it has', function () {
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'hero' => ['display' => 'Hero'],
            'quote' => ['display' => 'Quote'],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'heros'])
        ->assertHasErrors(["set 'heros' not found — available: hero, quote"]);
});
