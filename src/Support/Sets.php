<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Replicator;

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
}
