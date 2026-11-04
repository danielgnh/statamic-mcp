<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Support\Sets;
use Danielgnh\StatamicMcp\Support\TextEdit;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Arr;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

trait MergesSets
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $except
     * @param  list<string>  $allowed
     */
    abstract private function rejectUnknownFields(Fields $fields, array $data, string $path = '', array $except = [], array $allowed = [], mixed $stored = null): void;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function rejectReservedKeys(array $data): void;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function mergeSetPatches(Blueprint $blueprint, array $data, array $current): array
    {
        $this->rejectReservedKeys($data);

        $data = $this->mergeSetPatchesIn($blueprint->fields(), $data, $current, '');

        $this->rejectUnknownFields($blueprint->fields(), $data, except: ['slug'], stored: $current);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $before
     * @return array<string, list<string>>
     */
    protected function addedSets(Blueprint $blueprint, array $saved, array $before): array
    {
        $ids = fn (mixed $sets): array => is_array($sets)
            ? array_filter(array_map(fn (mixed $set) => is_array($set) ? data_get($set, Sets::idHandle()) : null, $sets), is_string(...))
            : [];

        return collect($saved)
            ->filter(fn (mixed $value, int|string $handle) => Sets::addressable($blueprint->field((string) $handle)))
            ->map(fn (mixed $sets, int|string $handle) => array_values(array_diff($ids($sets), $ids($before[$handle] ?? null))))
            ->filter()
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $current
     * @return array<array-key, mixed>
     */
    private function mergeSetPatchesIn(Fields $fields, array $data, array $current, string $path): array
    {
        foreach ($data as $handle => $patch) {
            $field = $fields->get($handle);
            $at = ltrim("{$path}.{$handle}", '.');

            if (TextEdit::accepts($field, $patch)) {
                $data[$handle] = TextEdit::apply($field, $current[$handle] ?? null, $patch, $at);

                continue;
            }

            if (! is_array($patch) || ! Sets::addressable($field)) {
                continue;
            }

            if ($patch === []) {
                throw new ToolException(sprintf(
                    '%s is empty — send null to clear the field, the list of its %ss to replace it, or an object keyed by %s id to merge',
                    $at,
                    Sets::noun($field),
                    Sets::noun($field),
                ));
            }

            $data[$handle] = array_is_list($patch)
                ? $this->mergeList($field, $patch, $current[$handle] ?? null, $at)
                : $this->mergeSets($field, $patch, $current[$handle] ?? null, $at);
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $patches
     * @return list<mixed>
     */
    private function mergeSets(Field $field, array $patches, mixed $sets, string $path): array
    {
        $idHandle = Sets::idHandle();
        $noun = Sets::noun($field);
        $sets = is_array($sets) ? array_values($sets) : [];
        $byId = Sets::positionsById($sets, $idHandle);

        foreach ($patches as $id => $patch) {
            $id = (string) $id;
            $index = Sets::find($field, $sets, $byId, $id, $path);

            if ($patch === null) {
                unset($sets[$index]);

                continue;
            }

            if (! is_array($patch) || array_is_list($patch)) {
                throw new ToolException("{$path}.{$id} must be an object of field values to merge into that {$noun}, or null to remove it");
            }

            if (array_key_exists($idHandle, $patch) && (! is_scalar($patch[$idHandle]) || (string) $patch[$idHandle] !== $id)) {
                throw new ToolException(sprintf("%s.%s carries %s %s — a %s's id is its key and never changes", $path, $id, $idHandle, json_encode($patch[$idHandle]), $noun));
            }

            if (array_key_exists('type', $patch) && $patch['type'] !== data_get($sets[$index], 'type')) {
                throw new ToolException(sprintf('%s.%s is a %s set — to change its type, send the list of sets with this one in its new type, which replaces it', $path, $id, data_get($sets[$index], 'type')));
            }

            $sets[$index] = $this->mergeSet($field, $sets[$index], $patch, "{$path}.{$id}");
        }

        return array_values($sets);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function mergeList(Field $field, array $items, mixed $sets, string $path): array
    {
        $idHandle = Sets::idHandle();
        $sets = is_array($sets) ? array_values($sets) : [];
        $byId = Sets::positionsById($sets, $idHandle);
        $kept = [];
        $new = [];

        foreach ($items as $position => $item) {
            $id = is_array($item) ? ($item[$idHandle] ?? null) : null;

            if (! is_scalar($id) || (string) $id === '') {
                $new[] = $position;

                continue;
            }

            $id = (string) $id;
            $matches = $byId[$id] ?? [];

            if ($matches === [] && Arr::except($item, Sets::reservedKeys($field)) === []) {
                throw Sets::unknown($field, $sets, $id, $path);
            }

            if (count($matches) !== 1) {
                $new[] = $position;

                continue;
            }

            if (isset($kept[$id])) {
                throw new ToolException(sprintf("%s lists the %s with id '%s' twice", $path, Sets::noun($field), $id));
            }

            $kept[$id] = true;
            $set = $sets[$matches[0]];

            if (array_key_exists('type', $item) && $item['type'] !== data_get($set, 'type')) {
                $items[$position] = Sets::withoutUnaddressableIds(Sets::fieldsOf($field, $item), $item);

                continue;
            }

            $items[$position] = $this->mergeSet($field, $set, $item, "{$path}.{$id}");
        }

        return Sets::withoutUnaddressableIdsAt($field, $items, $new);
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  array<array-key, mixed>  $patch
     * @return array<string, mixed>
     */
    private function mergeSet(Field $field, array $set, array $patch, string $path): array
    {
        return array_merge($set, $this->mergeSetPatchesIn(Sets::fieldsOf($field, $set), $patch, $set, $path));
    }
}
