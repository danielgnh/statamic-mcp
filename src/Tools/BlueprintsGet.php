<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\GuidelineFiles;
use Danielgnh\StatamicMcp\Support\Sets;
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
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Date;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

#[Name('blueprints_get')]
#[Description('Returns a blueprint\'s fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Pass type (collection|taxonomy|global) and the resource handle from statamic_overview; optionally a specific blueprint handle (defaults to the first). Relation-field examples are placeholders — replace them with real IDs. Fields with a null example carry a note in example_notes; read a real value from existing content for those. Cross-check each field\'s rules — examples satisfy shape, not every validation rule. Replicator and Bard fields list their sets (page builder blocks) with each set\'s display name, group, and instructions — follow a set\'s instructions when choosing and filling it, and never add a set marked hidden. Pass set with a set\'s handle for its fields. Grid and group fields list their nested fields; the example shows one set or row, and example_notes keys notes on nested values by path, like page_builder.0.image. When the site has written guidelines for this collection or blueprint, they come back in guidelines — follow them.')]
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
            'set' => $schema->string()
                ->description('A set handle from the sets of a replicator or Bard field. Returns only that set, with its fields, instead of the whole blueprint. When a handle has different fields in different places, the error lists their paths; pass one of those instead.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(
            [
                'type' => 'required|string|in:collection,taxonomy,global',
                'handle' => 'required|string',
                'blueprint' => 'nullable|string',
                'set' => 'nullable|string',
            ],
            [
                'type.in' => 'type must be one of: collection, taxonomy, global.',
            ],
        );

        $type = $request->get('type');
        $handle = $request->get('handle');

        $this->ensureExposed($this->configKey($type), $handle);

        // A blueprint is the field schema for a resource — gate reading it on the
        // same native permission the content read tools require, so an exposed
        // handle the user can't view doesn't leak its shape through this tool.
        $this->ensurePermission($this->user($request), $this->viewPermission($type, $handle));

        $blueprints = $this->blueprintsFor($type, $handle);

        if ($blueprints->isEmpty()) {
            return Response::error(sprintf("%s '%s' has no blueprint defined", $type, $handle));
        }

        $requested = $request->get('blueprint');

        if ($requested !== null && ! $blueprints->has($requested)) {
            return $this->notFound('blueprint', $requested, $blueprints->keys()->all());
        }

        $blueprint = $requested === null ? $blueprints->first() : $blueprints->get($requested);

        if ($blueprint === null) {
            return $this->notFound('blueprint', (string) $requested, $blueprints->keys()->all());
        }

        if (filled($set = $request->get('set'))) {
            return $this->json([
                'type' => $type,
                'handle' => $handle,
                'blueprint' => $blueprint->handle(),
                ...$this->setPayload($blueprint, $set),
            ]);
        }

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

            [$value, $fieldNotes] = $this->exampleFor($field, $field->handle());

            $example[$field->handle()] = $value;

            $notes = [...$notes, ...$fieldNotes];
        }

        $payload = [
            'type' => $type,
            'handle' => $handle,
            'blueprint' => $blueprint->handle(),
            'available_blueprints' => $blueprints->keys()->values()->all(),
            ...array_filter(['guidelines' => app(GuidelineFiles::class)->for($this->configKey($type), $handle, (string) $blueprint->handle())]),
            'fields' => $fields,
            'example' => $example,
        ];

        if ($notes !== []) {
            $payload['example_notes'] = $notes;
        }

        return $this->json($payload);
    }

    /**
     * The native permission that gates viewing this resource's content — and
     * therefore its schema. Mirrors statamic_overview / globals_get: v6 has no
     * 'view {handle} globals', so edit is the only per-set gate for globals.
     */
    private function viewPermission(string $type, string $handle): string
    {
        return match ($type) {
            'collection' => "view {$handle} entries",
            'taxonomy' => "view {$handle} terms",
            'global' => "edit {$handle} globals",
            default => throw new InvalidArgumentException("Unknown resource type [{$type}]."),
        };
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
        /** @var iterable<Blueprint> $blueprints */
        $blueprints = match ($type) {
            'collection' => Collection::findByHandle($handle)?->entryBlueprints() ?? [],
            'taxonomy' => Taxonomy::findByHandle($handle)?->termBlueprints() ?? [],
            'global' => array_filter([GlobalSet::findByHandle($handle)?->blueprint()]),
            default => throw new InvalidArgumentException("Unknown resource type [{$type}]."),
        };

        return collect($blueprints)->keyBy(fn (Blueprint $blueprint) => (string) $blueprint->handle());
    }

    /**
     * @return array<string, mixed>
     */
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

        $targets = match ($field->type()) {
            'assets' => ['container', 'max_files'],
            'entries' => ['collections', 'max_items'],
            'terms' => ['taxonomies', 'max_items'],
            'users' => ['max_items'],
            default => [],
        };

        foreach ($targets as $key) {
            if (filled($value = data_get($config, $key))) {
                $descriptor[$key] = $value;
            }
        }

        if ($sets = Sets::of($field)) {
            $descriptor['sets'] = array_map($this->describeSet(...), $sets);
        }

        $fieldtype = $field->fieldtype();

        if ($fieldtype instanceof Grid || $fieldtype instanceof Group) {
            $descriptor['fields'] = $this->describeFields($fieldtype->fields());
        }

        return $descriptor;
    }

    /**
     * A set as its field lists it: enough to pick one. Its fields come with
     * a lookup by handle, see setPayload().
     *
     * @param  array{handle: string, display: ?string, group: ?string, instructions: ?string, hidden: bool, fields: Fields}  $set
     * @return array<string, mixed>
     */
    private function describeSet(array $set): array
    {
        return array_filter([
            'handle' => $set['handle'],
            'display' => $set['display'],
            'group' => $set['group'],
            'instructions' => $set['instructions'],
            'hidden' => $set['hidden'] ?: null,
        ], filled(...));
    }

    /**
     * One set with its fields, described recursively. Sets nested in them
     * are listed by handle again, so every response stays one level deep.
     *
     * @return array<string, mixed>
     */
    private function setPayload(Blueprint $blueprint, string $set): array
    {
        [$found, $fields] = $this->findSet($blueprint, $set);

        return ['set' => [...$this->describeSet($found), 'fields' => $fields]];
    }

    /**
     * The set a handle or path names. A handle is looked up everywhere in
     * the blueprint, inside other sets too, and may turn up more than once
     * (a section that offers the page builder's sections again). That is
     * one answer while every match has the same fields, and an error naming
     * the paths when they differ.
     *
     * @return array{0: array{handle: string, display: ?string, group: ?string, instructions: ?string, hidden: bool, fields: Fields}, 1: array<int, array<string, mixed>>}
     */
    private function findSet(Blueprint $blueprint, string $set): array
    {
        $sets = collect($this->setsIn($blueprint->fields()));

        $matches = $sets
            ->filter(fn (array $found, string $path) => $path === $set || $found['handle'] === $set)
            ->sortBy(fn (array $found, string $path) => substr_count($path, '.'));

        if ($matches->isEmpty()) {
            throw new ToolException($this->notFoundMessage('set', $set, $sets->pluck('handle')->unique()->values()->all()));
        }

        $fields = $this->describeFields($matches->first()['fields']);

        if ($matches->contains(fn (array $found) => $this->describeFields($found['fields']) !== $fields)) {
            throw new ToolException(sprintf(
                "set '%s' has different fields in %s — pass the path of the one you mean as set",
                $set,
                $matches->keys()->implode(', '),
            ));
        }

        return [$matches->first(), $fields];
    }

    /**
     * Every set in these fields, keyed by its path of field and set handles
     * (page_builder.section_combined.sections.section_hero), including sets
     * inside other sets, grids, and groups.
     *
     * @return array<string, array{handle: string, display: ?string, group: ?string, instructions: ?string, hidden: bool, fields: Fields}>
     */
    private function setsIn(Fields $fields, string $prefix = ''): array
    {
        $sets = [];

        foreach ($fields->all() as $field) {
            $path = ltrim("{$prefix}.{$field->handle()}", '.');

            foreach (Sets::of($field) as $set) {
                $sets["{$path}.{$set['handle']}"] = $set;
                $sets = [...$sets, ...$this->setsIn($set['fields'], "{$path}.{$set['handle']}")];
            }

            $fieldtype = $field->fieldtype();

            if ($fieldtype instanceof Grid || $fieldtype instanceof Group) {
                $sets = [...$sets, ...$this->setsIn($fieldtype->fields(), $path)];
            }
        }

        return $sets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function describeFields(Fields $fields): array
    {
        return $fields->all()->map($this->describe(...))->values()->all();
    }

    /**
     * An example in the stored shape, plus notes keyed by their path in the
     * example. A replicator gets one set, a grid one row, and a group one
     * object, each built from the examples of its own fields.
     *
     * @return array{0: mixed, 1: array<string, string>}
     */
    private function exampleFor(Field $field, string $path): array
    {
        return match ($field->type()) {
            'replicator' => $this->replicatorExample($field, $path),
            'grid' => $this->gridExample($field, $path),
            'group' => $this->groupExample($field, $path),
            default => $this->leafExample($field, $path),
        };
    }

    /**
     * Bounded example generation: real examples for a fixed
     * set of fieldtypes, obviously-fake placeholders for relation fields, and
     * a null + note fallback for everything else (bard, link, …).
     *
     * @return array{0: mixed, 1: array<string, string>}
     */
    private function leafExample(Field $field, string $path): array
    {
        [$value, $note] = match ($field->type()) {
            'text' => ['Example text', null],
            'textarea' => ['A longer example paragraph of plain text.', null],
            'markdown' => ["## Example Heading\n\nExample **markdown** body.", null],
            'slug' => ['example-slug', null],
            'integer' => [42, null],
            'float' => [3.14, null],
            'toggle' => [true, null],
            'date' => $this->dateExample($field),
            // multi-selects store arrays
            'select' => $this->firstOption($field, wrapInArray: (bool) ($field->config()['multiple'] ?? false)),
            'radio', 'button_group' => $this->firstOption($field),
            'checkboxes' => $this->firstOption($field, wrapInArray: true),
            'entries' => $this->relationshipExample($field, 'REPLACE-WITH-REAL-ENTRY-ID'),
            'terms' => $this->relationshipExample($field, 'REPLACE-WITH-REAL-TERM-ID'),
            'users' => $this->relationshipExample($field, 'REPLACE-WITH-REAL-USER-ID'),
            'assets' => $this->assetsFieldExample($field),
            'bard' => $this->bardExample($field),
            default => $this->noExample($field),
        };

        return [$value, $note === null ? [] : [$path => $note]];
    }

    /**
     * @return array{0: null, 1: string}
     */
    private function noExample(Field $field): array
    {
        return [null, sprintf(
            "no example generated for fieldtype '%s' — read a real value from existing content before writing this field",
            $field->type(),
        )];
    }

    /**
     * The fields of a set, grid row, or group as one example object, with
     * their notes keyed by path. Computed fields stay out, like at the top
     * level.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function exampleObject(Fields $fields, string $path): array
    {
        $example = [];
        $notes = [];

        foreach ($fields->all() as $field) {
            $key = "{$path}.{$field->handle()}";

            if ($field->visibility() === 'computed') {
                $notes[$key] = 'computed — not writable';

                continue;
            }

            [$value, $fieldNotes] = $this->exampleFor($field, $key);

            $example[$field->handle()] = $value;

            $notes = [...$notes, ...$fieldNotes];
        }

        return [$example, $notes];
    }

    /**
     * One set in the stored shape: its type plus an example of each of its
     * fields. id and enabled are generated on write.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function replicatorExample(Field $field, string $path): array
    {
        /** @var Replicator $fieldtype */
        $fieldtype = $field->fieldtype();

        if (($set = $this->firstSet($field)) === null) {
            return [[], []];
        }

        [$values, $notes] = $this->exampleObject($fieldtype->fields($set), "{$path}.0");

        $note = sprintf('shows one %s set — sets lists every set type with its fields. Each set is an object with its type plus its field values; id and enabled are optional.', $set);

        return [[['type' => $set, ...$values]], [$path => $note, ...$notes]];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function gridExample(Field $field, string $path): array
    {
        /** @var Grid $fieldtype */
        $fieldtype = $field->fieldtype();

        [$row, $notes] = $this->exampleObject($fieldtype->fields(), "{$path}.0");

        return [[$row], $notes];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function groupExample(Field $field, string $path): array
    {
        /** @var Group $fieldtype */
        $fieldtype = $field->fieldtype();

        return $this->exampleObject($fieldtype->fields(), $path);
    }

    /**
     * Bard keeps a null example. With sets, the note shows what a set node
     * looks like, since HTML (which preProcess() converts) cannot hold one.
     *
     * @return array{0: null, 1: string}
     */
    private function bardExample(Field $field): array
    {
        if (($set = $this->firstSet($field)) === null) {
            return $this->noExample($field);
        }

        return [null, sprintf(
            'no example generated for fieldtype \'bard\' — send an HTML string, which is converted to ProseMirror nodes, or the nodes themselves. A set is a node {"type":"set","attrs":{"values":{"type":"%s", ...its field values}}}, and sets lists every set type; HTML cannot hold sets.',
            $set,
        )];
    }

    /**
     * The first set the CP offers editors, skipping hidden ones.
     */
    private function firstSet(Field $field): ?string
    {
        return data_get(collect(Sets::of($field))->reject(fn (array $set) => $set['hidden'])->first(), 'handle');
    }

    /**
     * A date example in the shape Statamic stores and entries_get returns:
     * the field's own save format, which the fieldtype's process() produces
     * from an ISO instant the same way it does for the CP's date picker.
     * mode:range stores a start/end pair.
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function dateExample(Field $field): array
    {
        /** @var Date $fieldtype */
        $fieldtype = $field->fieldtype();

        $value = $fieldtype->config('mode', 'single') === 'range'
            ? ['start' => '2026-01-15T09:30:00.000Z', 'end' => '2026-01-16T09:30:00.000Z']
            : '2026-01-15T09:30:00.000Z';

        return [$fieldtype->process($value), null];
    }

    /**
     * First option of a select/radio/button_group/checkboxes field. Options
     * may be an associative map (value => label), a plain list of values, or
     * the list of key/value pairs the CP saves.
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

        $first = array_is_list($options) ? data_get($options[0], 'key', $options[0]) : array_key_first($options);

        return [$wrapInArray ? [$first] : $first, null];
    }

    /**
     * Relationship fields store a single id when max_items is 1 and a list
     * otherwise (vendor Fieldtypes\Relationship::process).
     *
     * @return array{0: mixed, 1: null}
     */
    private function relationshipExample(Field $field, string $placeholder): array
    {
        return [$field->get('max_items') === 1 ? $placeholder : [$placeholder], null];
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
            'stores asset paths relative to the container root%s — %s. Find existing paths with assets_list, or upload new files with assets_upload, then use the returned path.',
            $container ? sprintf(" (container '%s')", $container) : ' (no container configured on the field — statamic_overview lists the available ones)',
            $single ? 'max_files is 1, so pass a single string path' : 'pass a list of path strings',
        );

        return [$single ? 'REPLACE-WITH-REAL-ASSET-PATH' : ['REPLACE-WITH-REAL-ASSET-PATH'], $note];
    }
}
