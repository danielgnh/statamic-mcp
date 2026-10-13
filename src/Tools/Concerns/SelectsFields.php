<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Support\Sets;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Str;
use JsonSerializable;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

/**
 * The fields parameter of the get tools: which fields come back, which
 * single sets of a page builder, and which large values are previewed.
 */
trait SelectsFields
{
    // Bytes (strlen) of encoded JSON before truncation — byte-based on purpose:
    // it approximates token cost; multibyte characters count per-byte.
    private const int PREVIEW_THRESHOLD = 500;

    // Characters of plain-text preview kept (Str::limit is mb-safe — never cuts mid-character).
    private const int PREVIEW_LENGTH = 300;

    // A Replicator field (a page builder) over this many bytes becomes an
    // index of its sets instead: enough to keep a few blocks inline, while a
    // long page costs one short call to find a set's id.
    private const int SET_INDEX_THRESHOLD = 4096;

    private const int SET_PREVIEW_LENGTH = 60;

    /**
     * fields entries are top-level handles, or handle.id to select one set
     * (or row) of a Replicator or Grid field by the id Statamic stamps on it.
     *
     * @param  list<string>  $requested
     * @return array{0: list<string>, 1: array<string, list<string>>} the handles, and the selected set ids by handle
     */
    private function parseFieldSelection(array $requested, Blueprint $blueprint): array
    {
        $handles = [];
        $sets = [];

        foreach ($requested as $selector) {
            $handle = Str::before($selector, '.');

            $handles[] = $handle;

            if (str_contains($selector, '.')) {
                $sets[$handle][] = Str::after($selector, '.');
            }
        }

        $handles = array_values(array_unique($handles));

        $this->assertKnownFields($handles, $blueprint);

        foreach ($sets as $handle => $ids) {
            if (in_array($handle, $requested, true)) {
                throw new ToolException("fields lists both {$handle} and {$handle}.{$ids[0]} — pass the handle for the whole field, or handle.id for single sets");
            }

            $field = $blueprint->field($handle);

            if (! Sets::addressable($field)) {
                throw new ToolException($field?->type() === 'bard'
                    ? sprintf('%s.%s selects a set by id, but Bard sets live inside the Bard value and can\'t be addressed that way — fetch the whole field with fields: ["%s"]', $handle, $ids[0], $handle)
                    : sprintf('%s.%s selects a set by id, which only Replicator and Grid fields have — %s is a %s field', $handle, $ids[0], $handle, $field?->type()));
            }
        }

        return [$handles, $sets];
    }

    /**
     * The selected sets, as an object keyed by id: the shape the update
     * tools merge by id, so sending it back can never drop a sibling.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $selectedSets
     * @return array<string, mixed>
     */
    private function withSelectedSets(array $data, array $selectedSets, Blueprint $blueprint): array
    {
        foreach ($selectedSets as $handle => $ids) {
            $field = $blueprint->field($handle);
            $sets = $this->decoded($data[$handle] ?? []);

            $data[$handle] = collect($ids)
                ->mapWithKeys(fn (string $id) => [$id => $sets[Sets::find($field, $sets, $id, $handle)]])
                ->all();
        }

        return $data;
    }

    /**
     * @param  list<string>  $requestedFields
     */
    private function assertKnownFields(array $requestedFields, Blueprint $blueprint): void
    {
        if ($requestedFields === []) {
            return;
        }

        // 'slug' is v6's auto-injected blueprint field (ensure*BlueprintFields);
        // it lives outside data() and is always returned as a top-level response key,
        // so it's never a selectable data field.
        $handles = $blueprint->fields()->all()->keys()->reject(fn ($handle) => $handle === 'slug')->values()->all();
        $unknown = array_values(array_diff($requestedFields, $handles));

        if ($unknown === []) {
            return;
        }

        sort($handles);

        throw new ToolException(sprintf(
            'unknown field%s %s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            implode(', ', $handles),
        ));
    }

    /**
     * Long Bard/markdown values become {__preview, truncated, note} objects,
     * and large Replicator values an index of their sets under the same
     * keys, unless explicitly requested via fields.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $requestedFields
     * @return array<string, mixed>
     */
    private function withRichTextPreviews(array $data, Blueprint $blueprint, array $requestedFields): array
    {
        foreach ($data as $handle => $value) {
            if (in_array($handle, $requestedFields, true)) {
                continue;
            }

            $field = $blueprint->fields()->all()->get($handle);
            if (! $field) {
                continue;
            }

            $indexed = Sets::indexed($field);

            if (! $indexed && ! in_array($field->type(), ['bard', 'markdown'], true)) {
                continue;
            }

            // Augmented values are JsonSerializable wrappers; normalize before measuring.
            $raw = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;

            $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            if (strlen($encoded) <= ($indexed ? self::SET_INDEX_THRESHOLD : self::PREVIEW_THRESHOLD)) {
                continue;
            }

            $data[$handle] = $indexed
                ? $this->setIndex($field, $this->decoded($raw))
                : [
                    '__preview' => Str::limit($this->plainText($raw), self::PREVIEW_LENGTH),
                    'truncated' => true,
                    'note' => sprintf('NOT writable — fetch raw field before editing: %s with fields: ["%s"]', $this->name(), $handle),
                ];
        }

        return $data;
    }

    /**
     * @param  list<mixed>  $sets
     * @return array<string, mixed>
     */
    private function setIndex(Field $field, array $sets): array
    {
        $handle = $field->handle();
        $idHandle = Sets::idHandle();

        $index = array_map(function (array $set) use ($field, $idHandle) {
            $entry = ['id' => data_get($set, $idHandle)];

            if (is_string($type = data_get($set, 'type'))) {
                $entry['type'] = $type;
            }

            if (data_get($set, 'enabled', true) === false) {
                $entry['enabled'] = false;
            }

            $entry['preview'] = $this->setPreview($field, $set);

            return $entry;
        }, array_values(array_filter($sets, is_array(...))));

        return [
            '__preview' => sprintf('%d %s', count($index), Str::plural('set', count($index))),
            'truncated' => true,
            'sets' => $index,
            'note' => sprintf(
                'NOT writable — fetch the whole field with %s fields: ["%s"], or one set with fields: ["%s.<id>"]; the update tool merges into one set with {"%s": {"<id>": {...}}}',
                $this->name(),
                $handle,
                $handle,
                $handle,
            ),
        ];
    }

    /**
     * The set's own text, so an agent can tell blocks of one type apart:
     * its title-like field, else its first filled text-like field, else
     * its first string of any kind.
     *
     * @param  array<string, mixed>  $set
     */
    private function setPreview(Field $field, array $set): string
    {
        $texts = Sets::fieldsOf($field, $set)->all()
            ->filter(fn (Field $own) => in_array($own->type(), ['text', 'textarea', 'markdown', 'bard'], true) && filled(data_get($set, $own->handle())));

        $text = $texts->first(fn (Field $own) => in_array($own->handle(), ['title', 'heading', 'headline', 'name', 'label'], true)) ?? $texts->first();

        $value = $text
            ? data_get($set, $text->handle())
            : collect($set)->except(Sets::reservedKeys($field))->first(fn ($value) => is_string($value) && trim($value) !== '');

        return Str::limit(trim(strip_tags(is_string($value) ? $value : $this->plainText($value ?? $set))), self::SET_PREVIEW_LENGTH);
    }

    /**
     * Augmented sets are Values objects; plain arrays either way.
     *
     * @return list<mixed>
     */
    private function decoded(mixed $value): array
    {
        $decoded = json_decode((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Extract readable text from a ProseMirror document (Bard stores
     * {type, content: [{type: text, text: ...}]} trees) or pass strings through.
     */
    private function plainText(mixed $value): string
    {
        if (is_string($value)) {
            return strip_tags($value); // augmented bard is HTML — markup wastes preview budget
        }

        if (! is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = '';

        array_walk_recursive($value, function ($item, $key) use (&$text) {
            if ($key === 'text' && is_string($item)) {
                $text .= $item.' ';
            }
        });

        return trim($text);
    }
}
