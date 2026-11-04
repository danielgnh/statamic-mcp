<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Facades\Exceptions;
use Statamic\Exceptions\AssetNotFoundException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Field;
use Statamic\Fields\FieldtypeRepository;

function fieldtypeRunner(): object
{
    return new class
    {
        use ValidatesBlueprintData;

        #[Override]
        protected function normalize(mixed $value): mixed
        {
            return $value;
        }

        public function run(Field $field, callable $process): mixed
        {
            return $this->orUnprocessable($field, $process);
        }

        public function process(array $fields, array $values, array $only): array
        {
            $blueprint = Blueprint::make('hostile')->setContents(['fields' => $fields]);

            return $this->processAgainstBlueprint($blueprint, $values, $only);
        }
    };
}

beforeEach(fn () => Exceptions::fake());

it('answers a hostile value with a tool error naming the field, whichever way a core fieldtype chokes on it', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::tags();
    Fixtures::blog();
    Taxonomy::make('topics')->save();

    $this->actingAs(Fixtures::makeSuper());

    $text = [['handle' => 'body', 'field' => ['type' => 'text']]];

    $configs = [
        ...app(FieldtypeRepository::class)->classes()->keys()->map(fn (string $type) => ['type' => $type])->all(),
        ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['copy' => ['fields' => $text]]]]],
        ['type' => 'bard', 'sets' => ['main' => ['sets' => ['copy' => ['fields' => $text]]]]],
        ['type' => 'grid', 'fields' => $text],
        ['type' => 'group', 'fields' => $text],
        ['type' => 'date', 'mode' => 'range'],
        ['type' => 'date', 'time_enabled' => true],
        ['type' => 'terms', 'taxonomies' => ['tags'], 'max_items' => 1],
        ['type' => 'terms', 'taxonomies' => ['tags', 'topics']],
        ['type' => 'entries', 'collections' => ['blog'], 'max_items' => 1],
        ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        ['type' => 'users', 'max_items' => 1],
        ['type' => 'select', 'options' => ['a', 'b'], 'multiple' => true],
        ['type' => 'link', 'collections' => ['blog']],
    ];

    $values = [
        'TBD', 123, 1.5, true, [], ['a'], ['a' => 'b'], [['x' => 1]], [[['y']]], [null],
        ['type' => 'nope'], [['type' => 'text', 'text' => ['z']]], [['id' => 'x', 'type' => 'nope']],
        '0000-13-45', '{{ x }}', ['date' => 'x', 'time' => 'y'], ['start' => 'x', 'end' => []],
        ['id' => [], 'type' => []], 'tags::missing', 'entry::missing',
    ];

    $runner = fieldtypeRunner();
    $escaped = [];

    foreach ($configs as $config) {
        foreach ($values as $value) {
            try {
                $runner->process([['handle' => 'field', 'field' => $config]], ['field' => $value], ['field']);
            } catch (ToolException) {
                continue;
            } catch (Throwable $e) {
                $escaped[] = sprintf('%s with %s: %s: %s', json_encode($config), json_encode($value), $e::class, $e->getMessage());
            }
        }
    }

    expect($escaped)->toBe([]);
});

it('reports a PHP error while still naming the field, because it can be a bug as easily as a bad shape', function (Throwable $failure) {
    $field = new Field('field', ['type' => 'text']);

    try {
        fieldtypeRunner()->run($field, fn () => throw $failure);
        $thrown = null;
    } catch (ToolException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toContain('field field has a value its fieldtype (text)')
        ->and($thrown?->getPrevious())->toBe($failure);

    Exceptions::assertReported($failure::class);
})->with([
    'a type error' => fn () => new TypeError('array given'),
    'a call on a missing method' => fn () => new Error('Call to undefined method'),
    'a warning' => fn () => new ErrorException('Undefined array key'),
]);

it('does not report a value the fieldtype refuses, because that one is for the agent to correct', function (Throwable $failure) {
    expect(fn () => fieldtypeRunner()->run(new Field('field', ['type' => 'text']), fn () => throw $failure))
        ->toThrow(ToolException::class);

    Exceptions::assertNothingReported();
})->with([
    'a bad argument' => fn () => new InvalidArgumentException('not a date'),
    'a missing asset' => fn () => new AssetNotFoundException('missing.png'),
]);

it('names the field when a validation rule chokes on its value, so an agent can correct it', function () {
    $fields = [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'opens_at', 'field' => ['type' => 'time']],
    ];

    expect(fn () => fieldtypeRunner()->process($fields, ['title' => 'Fine', 'opens_at' => ['not', 'a', 'time']], ['title', 'opens_at']))
        ->toThrow(ToolException::class, 'field opens_at has a value its fieldtype (time)');

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(TypeError::class);
});

it('blames no field it was sent when a stored value breaks validation, because the agent cannot fix that', function () {
    $fields = [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'opens_at', 'field' => ['type' => 'time']],
    ];

    expect(fn () => fieldtypeRunner()->process($fields, ['title' => 'Fine', 'opens_at' => ['stored', 'badly']], ['title']))
        ->toThrow(TypeError::class);
});

it('names the field and reports it when a rule throws, because the rule can be the site\'s own bug', function () {
    expect(fn () => fieldtypeRunner()->process([['handle' => 'code', 'field' => ['type' => 'text', 'validate' => ['min']]]], ['code' => 'anything'], ['code']))
        ->toThrow(ToolException::class, 'field code has a value its fieldtype (text)');

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(InvalidArgumentException::class);
});
