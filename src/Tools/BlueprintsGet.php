<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
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
use Statamic\Fieldtypes\Date;

#[Name('blueprints_get')]
#[Description('Returns a blueprint\'s fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Pass type (collection|taxonomy|global) and the resource handle from statamic_overview; optionally a specific blueprint handle (defaults to the first). Relation-field examples are placeholders — replace them with real IDs. Fields with a null example carry a note in example_notes; read a real value from existing content for those. Cross-check each field\'s rules — examples satisfy shape, not every validation rule.')]
#[IsReadOnly]
class BlueprintsGet extends Tool
{
    #[\Override]
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

            // computed fields are omitted from write processing (Fields::values()
            // rejects visibility=computed) — never offer them as writable
            if ($field->visibility() === 'computed') {
                $notes[$field->handle()] = 'computed — not writable';

                continue;
            }

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
            default => throw new InvalidArgumentException("Unknown resource type [{$type}]."),
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
            default => throw new InvalidArgumentException("Unknown resource type [{$type}]."),
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
            'rules' => array_values(array_unique(array_filter($field->rules()[$field->handle()] ?? [], is_string(...)))),
        ];

        if (($visibility = $field->visibility()) !== 'visible') {
            $descriptor['visibility'] = $visibility;
        }

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
            'date' => $this->dateExample($field),
            // multi-selects store arrays; Statamic silently accepts a scalar and saves the wrong shape
            'select' => $this->firstOption($field, wrapInArray: (bool) ($field->config()['multiple'] ?? false)),
            'radio' => $this->firstOption($field),
            'checkboxes' => $this->firstOption($field, wrapInArray: true),
            'entries' => [['REPLACE-WITH-REAL-ENTRY-ID'], null],
            'terms' => [['REPLACE-WITH-REAL-TERM-ID'], null],
            'users' => [['REPLACE-WITH-REAL-USER-ID'], null],
            'assets' => $this->assetsFieldExample($field),
            default => [null, sprintf(
                "no example generated for fieldtype '%s' — read a real value from existing content before writing this field",
                $field->type(),
            )],
        };
    }

    /**
     * A date example matching the shape the DateFieldtype validation rule
     * accepts (vendor src/Rules/DateFieldtype.php): the string format follows
     * the field's SAVE format, not time_enabled — the default save format is
     * 'Y-m-d H:i' (contains time), so a default-config date field requires
     * 'Y-m-d\TH:i:s.v\Z'; plain 'Y-m-d' only validates when a time-less
     * 'format' is configured. mode:range wants a start/end pair of the same.
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function dateExample(Field $field): array
    {
        /** @var Date $fieldtype */
        $fieldtype = $field->fieldtype();

        $hasTime = $fieldtype->formatHasTime();

        $start = $hasTime ? '2026-01-15T09:30:00.000Z' : '2026-01-15';
        $end = $hasTime ? '2026-01-16T09:30:00.000Z' : '2026-01-16';

        if ($fieldtype->config('mode', 'single') === 'range') {
            return [['start' => $start, 'end' => $end], null];
        }

        return [$start, null];
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
            return [null, sprintf(
                "fieldtype '%s' has no options configured — read a real value from existing content before writing this field",
                $field->type(),
            )];
        }

        $first = array_is_list($options) ? $options[0] : array_key_first($options);

        return [$wrapInArray ? [$first] : $first, null];
    }

    /**
     * Assets fields store paths relative to the field's container root —
     * a single string when max_files is 1, a list otherwise (vendor
     * Fieldtypes\Assets::process). Point the agent at the assets tools
     * instead of the generic null fallback.
     *
     * @return array{0: mixed, 1: string}
     */
    private function assetsFieldExample(Field $field): array
    {
        $container = $field->config()['container'] ?? null;
        $single = (int) ($field->config()['max_files'] ?? 0) === 1;

        $note = sprintf(
            'stores asset paths relative to the container root%s — %s. Find existing paths with assets_list, or upload new files with assets_upload, then use the returned path (not the id or url).',
            $container ? sprintf(" (container '%s')", $container) : ' (no container configured on the field — statamic_overview lists the available ones)',
            $single ? 'max_files is 1, so pass a single string path' : 'pass a list of path strings',
        );

        return [$single ? 'REPLACE-WITH-REAL-ASSET-PATH' : ['REPLACE-WITH-REAL-ASSET-PATH'], $note];
    }
}
