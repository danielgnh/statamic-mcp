<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Replicator;
use Statamic\Fieldtypes\RowId;

/**
 * The sets (page builder blocks) of a Replicator or Bard field. Reads both
 * config formats: grouped sets, and the legacy flat list that
 * Replicator::flattenedSetsConfig() detects the same way.
 */
class Sets
{
    /**
     * @return list<array{handle: string, display: ?string, group: ?string, instructions: ?string, hidden: bool, fields: Fields}>
     */
    public static function of(Field $field): array
    {
        $fieldtype = $field->fieldtype();
        $config = $field->get('sets');

        if (! $fieldtype instanceof Replicator || blank($config) || ! is_array($config)) {
            return [];
        }

        $legacy = ! Arr::has(Arr::first($config), 'sets');
        $groups = $legacy ? [['sets' => $config]] : $config;

        $sets = [];

        foreach ($groups as $groupHandle => $group) {
            foreach (Arr::wrap(data_get($group, 'sets')) as $handle => $set) {
                $sets[] = [
                    'handle' => (string) $handle,
                    'display' => data_get($set, 'display'),
                    'group' => $legacy ? null : (data_get($group, 'display') ?: Str::headline((string) $groupHandle)),
                    'instructions' => data_get($set, 'instructions'),
                    'hidden' => (bool) data_get($set, 'hide', false),
                    'fields' => $fieldtype->fields($handle),
                ];
            }
        }

        return $sets;
    }

    /**
     * Whether the field stores a list Statamic stamps ids on, so single sets
     * can be addressed by id: a Replicator's sets or a Grid's rows. Bard
     * extends Replicator but keeps its sets inside ProseMirror nodes.
     */
    public static function addressable(?Field $field): bool
    {
        $fieldtype = $field?->fieldtype();

        return ($fieldtype instanceof Replicator || $fieldtype instanceof Grid) && ! $fieldtype instanceof Bard;
    }

    /**
     * Whether a large value is read as an index of its sets: Replicators,
     * whose sets have a type and text to preview. A Grid's rows read like a
     * table, where an index of ids saves little.
     */
    public static function indexed(Field $field): bool
    {
        return self::addressable($field) && ! $field->fieldtype() instanceof Grid;
    }

    public static function idHandle(): string
    {
        return app(RowId::class)->handle();
    }

    /**
     * What one item of the field is called in errors and previews.
     */
    public static function noun(Field $field): string
    {
        return $field->fieldtype() instanceof Grid ? 'row' : 'set';
    }

    /**
     * The keys Statamic stores beside a set's fields.
     *
     * @return list<string>
     */
    public static function reservedKeys(Field $field): array
    {
        return $field->fieldtype() instanceof Grid
            ? [self::idHandle()]
            : ['type', 'enabled', self::idHandle()];
    }

    /**
     * The set's own fields, which a Replicator keys by set type.
     *
     * @param  array<string, mixed>  $set
     */
    public static function fieldsOf(Field $field, array $set): Fields
    {
        $fieldtype = $field->fieldtype();

        return $fieldtype instanceof Replicator
            ? $fieldtype->fields(is_scalar($type = data_get($set, 'type')) ? (string) $type : '')
            : $fieldtype->fields();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function withoutUnaddressableIds(Fields $fields, array $data): array
    {
        foreach ($data as $handle => $value) {
            $field = $fields->get((string) $handle);

            if (self::addressable($field) && is_array($value) && array_is_list($value)) {
                $data[$handle] = self::withoutUnaddressableIdsAt($field, $value, array_keys($value));
            }
        }

        return $data;
    }

    /**
     * @param  list<mixed>  $sets
     * @param  list<int>  $new  the positions of the sets the list adds
     * @return list<mixed>
     */
    public static function withoutUnaddressableIdsAt(Field $field, array $sets, array $new): array
    {
        $idHandle = self::idHandle();
        $sent = [];

        foreach ($new as $position) {
            if (is_array($sets[$position]) && is_scalar($id = $sets[$position][$idHandle] ?? null)) {
                $sent[(string) $id] = ($sent[(string) $id] ?? 0) + 1;
            }
        }

        foreach ($new as $position) {
            if (! is_array($set = $sets[$position])) {
                continue;
            }

            $id = $set[$idHandle] ?? null;

            if (! is_scalar($id) || in_array((string) $id, ['', '0'], true) || $sent[(string) $id] > 1) {
                unset($set[$idHandle]);
            }

            $sets[$position] = self::withoutUnaddressableIds(self::fieldsOf($field, $set), $set);
        }

        return $sets;
    }

    /**
     * The position of the set with that id. The id 0 is refused: an object
     * whose keys start at 0 is a list to PHP, so {"0": {...}} could never
     * round-trip as a set patch. Every other id, digits included, is a
     * string key.
     *
     * @param  array<int, mixed>  $sets
     */
    public static function find(Field $field, array $sets, string $id, string $path): int
    {
        $noun = self::noun($field);

        if ($id === '') {
            throw new ToolException(sprintf('pass %s.<id> with a %s id — the index of %s lists them', $path, $noun, $path));
        }

        if ($id === '0') {
            throw new ToolException(sprintf("the %s id 0 can't be addressed, PHP reads it as a list index — fetch or send the whole %s", $noun, $path));
        }

        $matches = self::positions($sets, $id);

        if (count($matches) > 1) {
            throw new ToolException(sprintf("id '%s' is shared by %d %ss in %s — fetch or send the whole field", $id, count($matches), $noun, $path));
        }

        return $matches[0] ?? throw self::unknown($field, $sets, $id, $path);
    }

    /**
     * @param  array<int, mixed>  $sets
     * @return list<int>
     */
    public static function positions(array $sets, string $id): array
    {
        $idHandle = self::idHandle();

        return array_keys(array_filter($sets, fn (mixed $set) => is_array($set) && (string) data_get($set, $idHandle) === $id));
    }

    /**
     * @param  array<array-key, mixed>  $sets
     */
    public static function unknown(Field $field, array $sets, string $id, string $path): ToolException
    {
        $noun = self::noun($field);
        $idHandle = self::idHandle();

        $known = collect($sets)
            ->filter(fn ($set) => filled(data_get($set, $idHandle)))
            ->map(fn ($set) => is_string($type = data_get($set, 'type'))
                ? sprintf('%s (%s)', data_get($set, $idHandle), $type)
                : (string) data_get($set, $idHandle))
            ->implode(', ');

        return new ToolException($known === ''
            ? sprintf("no %s with id '%s' in %s — it has no %ss with an id; fetch or send the whole field", $noun, $id, $path, $noun)
            : sprintf("no %s with id '%s' in %s — existing %ss: %s", $noun, $id, $path, $noun, $known));
    }
}
