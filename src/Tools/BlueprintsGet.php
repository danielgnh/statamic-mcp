<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

#[Name('blueprints_get')]
#[Description('Returns a blueprint\'s fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Pass type (collection|taxonomy|global) and the resource handle from statamic_overview; optionally a specific blueprint handle (defaults to the first). Relation-field examples are placeholders — replace them with real IDs. Fields with a null example carry a note in example_notes; read a real value from existing content for those.')]
#[IsReadOnly]
class BlueprintsGet extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['collection', 'taxonomy', 'global'])
                ->description('Resource type the handle belongs to.')
                ->required(),
            'handle' => $schema->string()
                ->description('Collection, taxonomy, or global set handle (see statamic_overview).')
                ->required(),
            'blueprint' => $schema->string()
                ->description("Blueprint handle. Defaults to the resource's first blueprint."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(
            [
                'type' => 'required|string|in:collection,taxonomy,global',
                'handle' => 'required|string',
                'blueprint' => 'nullable|string',
            ],
            [
                'type.in' => 'type must be one of: collection, taxonomy, global.',
            ],
        );

        $type = $request->get('type');
        $handle = $request->get('handle');

        $this->ensureExposed($this->configKey($type), $handle);

        $blueprints = $this->blueprintsFor($type, $handle);

        if ($blueprints->isEmpty()) {
            return Response::error(sprintf("%s '%s' has no blueprint defined", $type, $handle));
        }

        $requested = $request->get('blueprint');

        if ($requested !== null && ! $blueprints->has($requested)) {
            return $this->notFound('blueprint', $requested, $blueprints->keys()->all());
        }

        $blueprint = $requested === null ? $blueprints->first() : $blueprints->get($requested);

        $fields = [];
        $example = [];
        $notes = [];

        foreach ($blueprint->fields()->all() as $field) {
            $fields[] = $this->describe($field);

            [$value, $note] = $this->exampleFor($field);

            $example[$field->handle()] = $value;

            if ($note !== null) {
                $notes[$field->handle()] = $note;
            }
        }

        $payload = [
            'type' => $type,
            'handle' => $handle,
            'blueprint' => $blueprint->handle(),
            'available_blueprints' => $blueprints->keys()->values()->all(),
            'fields' => $fields,
            'example' => $example,
        ];

        if ($notes !== []) {
            $payload['example_notes'] = $notes;
        }

        return $this->json($payload);
    }

    /**
     * @return 'collections'|'taxonomies'|'globals'
     */
    private function configKey(string $type): string
    {
        return match ($type) {
            'collection' => 'collections',
            'taxonomy' => 'taxonomies',
            'global' => 'globals',
        };
    }

    /**
     * Blueprints of the resource, keyed by blueprint handle. ensureExposed()
     * already guaranteed the handle exists, so the lookups never return null.
     *
     * @return SupportCollection<string, Blueprint>
     */
    private function blueprintsFor(string $type, string $handle): SupportCollection
    {
        $blueprints = match ($type) {
            'collection' => Collection::findByHandle($handle)->entryBlueprints(),
            'taxonomy' => Taxonomy::findByHandle($handle)->termBlueprints(),
            'global' => collect([GlobalSet::findByHandle($handle)->blueprint()])->filter(),
        };

        return collect($blueprints)->keyBy(fn ($blueprint) => $blueprint->handle());
    }

    private function describe(Field $field): array
    {
        $config = $field->config();

        $descriptor = [
            'handle' => $field->handle(),
            'type' => $field->type(),
            'required' => $field->isRequired(),
            // closure/Rule-object rules are not JSON-serializable; writes still enforce them.
            // v6's injected title field carries 'required' twice (config flag + validate rule) — dedupe.
            'rules' => array_values(array_unique(array_filter($field->rules()[$field->handle()] ?? [], 'is_string'))),
        ];

        if (isset($config['options'])) {
            $descriptor['options'] = $config['options'];
        }

        if (isset($config['instructions'])) {
            $descriptor['instructions'] = $config['instructions'];
        }

        return $descriptor;
    }

    /**
     * Bounded example generation (spec §4 row 2): real examples for a fixed
     * set of fieldtypes, obviously-fake placeholders for relation fields, and
     * a null + note fallback for everything else (bard, replicator, grid, …).
     *
     * @return array{0: mixed, 1: ?string} [example value, note or null]
     */
    private function exampleFor(Field $field): array
    {
        return match ($field->type()) {
            'text' => ['Example text', null],
            'textarea' => ['A longer example paragraph of plain text.', null],
            'markdown' => ["## Example Heading\n\nExample **markdown** body.", null],
            'slug' => ['example-slug', null],
            'integer' => [42, null],
            'float' => [3.14, null],
            'toggle' => [true, null],
            'date' => ['2026-01-15', null],
            'select', 'radio' => $this->firstOption($field),
            'checkboxes' => $this->firstOption($field, wrapInArray: true),
            'entries' => [['REPLACE-WITH-REAL-ENTRY-ID'], null],
            'terms' => [['REPLACE-WITH-REAL-TERM-ID'], null],
            'users' => [['REPLACE-WITH-REAL-USER-ID'], null],
            default => [null, sprintf(
                "no example generated for fieldtype '%s' — read a real value from existing content before writing this field",
                $field->type(),
            )],
        };
    }

    /**
     * First option of a select/radio/checkboxes field. Options may be an
     * associative map (value => label) or a plain list of values.
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function firstOption(Field $field, bool $wrapInArray = false): array
    {
        $options = $field->config()['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return [null, sprintf("fieldtype '%s' has no options configured", $field->type())];
        }

        $first = array_is_list($options) ? $options[0] : array_key_first($options);

        return [$wrapInArray ? [$first] : $first, null];
    }
}
