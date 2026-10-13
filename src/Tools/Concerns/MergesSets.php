<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Support\Sets;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Arr;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Needs ValidatesBlueprintData in the same tool: the patch is checked
 * level by level here, so an untouched set is never held to the strictness
 * meant for what the agent sent.
 */
trait MergesSets
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $except
     * @param  list<string>  $allowed
     */
    abstract private function rejectUnknownFields(Fields $fields, array $data, string $path = '', array $except = [], array $allowed = []): void;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function rejectReservedKeys(array $data): void;

    abstract protected function normalize(mixed $value): mixed;

    /**
     * Validates a patch and merges its set patches. A Replicator or Grid
     * field takes its complete list, or an object keyed by the id Statamic
     * stamps on every set and row, which merges into just those sets — so
     * changing one field of one page builder block never costs the whole
     * page. A null set is removed, and a nested Replicator or Grid inside a
     * set merges the same way. The result is the complete list everything
     * downstream always saw; the other sets are saved as they are stored,
     * the way a Control Panel save keeps them.
     *
     * @param  array<string, mixed>  $data  the patch
     * @param  array<string, mixed>  $current  the values the patch merges over
     * @return array<string, mixed>
     */
    protected function mergeSetPatches(Blueprint $blueprint, array $data, array $current): array
    {
        $this->rejectReservedKeys($data);

        // 'slug' is v6's auto-injected blueprint field, a tool parameter, never a data key.
        return $this->mergeSetPatchesIn($blueprint->fields(), $data, $current, '', except: ['slug']);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $current
     * @param  list<string>  $except
     * @param  list<string>  $allowed
     * @return array<array-key, mixed>
     */
    private function mergeSetPatchesIn(Fields $fields, array $data, array $current, string $path, array $except = [], array $allowed = []): array
    {
        $merged = [];

        foreach ($data as $handle => $patch) {
            $field = $fields->get($handle);

            if (! is_array($patch) || ! Sets::addressable($field)) {
                continue;
            }

            // PHP can't tell {} from [], and {} would wipe the field, so neither clears it: null does.
            if ($patch === []) {
                throw new ToolException(sprintf(
                    '%s is empty — send null to clear the field, its complete list to replace it, or an object keyed by %s id to merge',
                    ltrim("{$path}.{$handle}", '.'),
                    Sets::noun($field),
                ));
            }

            if (array_is_list($patch)) {
                continue;
            }

            $data[$handle] = $this->mergeSets($field, $patch, data_get($current, $handle), ltrim("{$path}.{$handle}", '.'));
            $merged[] = $handle;
        }

        // What the agent sent is checked strictly, set patches above per set.
        $this->rejectUnknownFields($fields, Arr::except($data, $merged), $path, $except, $allowed);

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $patches  set patches keyed by id
     * @return list<array<string, mixed>>
     */
    private function mergeSets(Field $field, array $patches, mixed $sets, string $path): array
    {
        $idHandle = Sets::idHandle();
        $noun = Sets::noun($field);
        $sets = is_array($sets) ? array_values($sets) : [];

        foreach ($patches as $id => $patch) {
            $id = (string) $id;
            $index = Sets::find($field, $sets, $id, $path);

            if ($patch === null) {
                unset($sets[$index]);

                continue;
            }

            if (! is_array($patch) || array_is_list($patch)) {
                throw new ToolException("{$path}.{$id} must be an object of field values to merge into that {$noun}, or null to remove it");
            }

            $set = $sets[$index];

            if (array_key_exists($idHandle, $patch) && (! is_scalar($patch[$idHandle]) || (string) $patch[$idHandle] !== $id)) {
                throw new ToolException(sprintf("%s.%s carries %s %s — a %s's id is its key and never changes", $path, $id, $idHandle, json_encode($patch[$idHandle]), $noun));
            }

            if (array_key_exists('type', $patch) && $patch['type'] !== data_get($set, 'type')) {
                throw new ToolException(sprintf("%s.%s is a %s set — changing a set's type takes the complete list", $path, $id, data_get($set, 'type')));
            }

            if (! Sets::hasType($field, $set)) {
                throw new ToolException(sprintf("%s.%s is a set of type '%s', which the blueprint no longer defines — send the complete list to replace or drop it", $path, $id, data_get($set, 'type')));
            }

            if (array_key_exists('enabled', $patch) && ! is_bool($patch['enabled'])) {
                throw new ToolException("{$path}.{$id}.enabled must be true or false");
            }

            $fields = Sets::fieldsOf($field, $set);

            $patch = $this->withoutUnchangedStaleKeys($patch, $set, $fields, $field, "{$path}.{$id}");

            $patch = $this->mergeSetPatchesIn($fields, $patch, $set, "{$path}.{$id}", allowed: Sets::reservedKeys($field));

            $sets[$index] = array_merge($set, $patch);
        }

        return array_values($sets);
    }

    /**
     * A set fetched from the get tools can be sent back whole, also when it
     * stores a key its blueprint no longer defines, as long as that key is
     * unchanged. Changing or adding such a key stays an error.
     *
     * @param  array<array-key, mixed>  $patch
     * @param  array<string, mixed>  $set
     * @return array<array-key, mixed>
     */
    private function withoutUnchangedStaleKeys(array $patch, array $set, Fields $fields, Field $field, string $path): array
    {
        $known = [...$fields->all()->keys()->all(), ...Sets::reservedKeys($field)];

        foreach ($patch as $key => $value) {
            if (in_array($key, $known, true) || ! array_key_exists($key, $set)) {
                continue;
            }

            if ($this->normalize($value) !== $this->normalize($set[$key])) {
                throw new ToolException("{$path} stores {$key}, which its blueprint no longer defines — it can't be changed by id; send the complete list to replace or drop it");
            }

            unset($patch[$key]);
        }

        return $patch;
    }
}
