<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Support\Sets;
use Danielgnh\StatamicMcp\Support\TextEdit;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Assets\Assets;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;
use Statamic\Support\Arr;

trait ValidatesBlueprintData
{
    abstract protected function normalize(mixed $value): mixed;

    /**
     * Statamic silently stores unknown keys (typos become content) — reject
     * them instead, naming valid handles plus a Levenshtein "did you mean".
     * The check descends into replicator and Bard sets (whose type must be
     * one the field defines), grid rows, and groups. Asset references must
     * resolve in their field's container: processing would silently drop a
     * dangling path, and throw on a dangling id.
     * 'slug' is v6's auto-injected blueprint field; it is a
     * dedicated tool parameter, never a data key, so it's excluded here.
     *
     * @param  array<string, mixed>  $data
     */
    protected function rejectUnknownKeys(Blueprint $blueprint, array $data): void
    {
        $this->rejectReservedKeys($data);

        $this->rejectUnknownFields($blueprint->fields(), $data, except: ['slug']);
    }

    /**
     * Front-matter keys Statamic manages itself — data keys shadow them on
     * disk (fileData()), so a blueprint that happens to define a 'published'
     * toggle would let a create-only user persist publish state through data
     * (and the global-variables store silently strips 'origin' on
     * rehydration). Hard-rejected regardless of blueprint contents — called
     * directly by blueprint-less write paths (globals).
     *
     * @param  array<string, mixed>  $data
     */
    protected function rejectReservedKeys(array $data): void
    {
        $reserved = array_values(array_intersect(array_keys($data), ['id', 'origin', 'published', 'blueprint']));

        if ($reserved !== []) {
            throw new ToolException(sprintf(
                'field%s %s %s reserved — never writable via data',
                count($reserved) === 1 ? '' : 's',
                implode(', ', $reserved),
                count($reserved) === 1 ? 'is' : 'are',
            ));
        }
    }

    /**
     * The CP's save path (validate, then process()) fed the stored shape the
     * get tools return: preProcess() first turns it into the publish form's
     * shape, the only one the blueprint's rules understand, and process()
     * turns that back into exactly what the CP stores — a single-file asset
     * as a string, set ids, the date save format. Callers pass MERGED values
     * (existing + patch) so partial updates never false-fail required fields,
     * plus the CP's rule placeholder replacements (collection/site, and id on
     * updates) so rules like unique_entry_value scope correctly; only the
     * $only keys come back. Explicit nulls skip both steps: null is how an
     * agent clears a field, and preProcess() would swap in the default.
     * Field-level messages reach the model for one-round-trip self-correction.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array-key>  $only
     * @param  array<string, string>  $replacements
     * @return array<array-key, mixed>
     */
    protected function processAgainstBlueprint(Blueprint $blueprint, array $values, array $only, array $replacements = []): array
    {
        $fields = $blueprint->fields()->addValues($values);

        // Stored values are Statamic's own; the agent's may not survive a
        // fieldtype's preProcess() — name the field instead of a bare 500.
        $fields->setFields($fields->all()->map(function (Field $field) use ($only) {
            if ($field->value() === null) {
                return $field;
            }

            return in_array($field->handle(), $only, true)
                ? rescue(fn () => $field->preProcess(), fn () => throw $this->unprocessable($field), report: false)
                : $field->preProcess();
        }));

        try {
            $fields->validator()->withReplacements($replacements)->validate();
        } catch (ValidationException $e) {
            throw new ToolException('validation failed: '.json_encode(
                $this->keyedBySetId($e->errors(), $values),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ), $e->getCode(), $e);
        }

        return collect($only)->mapWithKeys(function (int|string $handle) use ($fields, $values) {
            $field = $fields->get($handle);
            $value = data_get($values, $handle);

            if ($field === null || $value === null) {
                return [$handle => $value];
            }

            return [$handle => rescue(fn () => $field->process()->value(), fn () => throw $this->unprocessable($field), report: false)];
        })->all();
    }

    /**
     * Statamic's validator names a set by its index, page_builder.0.heading;
     * the agent addresses it by id, so name it page_builder.wKJQXf2s.heading
     * wherever the item carries one.
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    private function keyedBySetId(array $errors, array $values): array
    {
        $idHandle = Sets::idHandle();

        return collect($errors)->mapWithKeys(function (array $messages, string $key) use ($values, $idHandle) {
            $lookup = [];
            $named = [];

            foreach (explode('.', $key) as $segment) {
                $lookup[] = $segment;
                $id = ctype_digit($segment) ? data_get($values, implode('.', [...$lookup, $idHandle])) : null;
                $named[] = is_string($id) && $id !== '' ? $id : $segment;
            }

            return [implode('.', $named) => $messages];
        })->all();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $except  handles the blueprint defines that are never data keys
     * @param  list<string>  $allowed  keys Statamic stores beside the fields (a set's type, row ids)
     */
    private function rejectUnknownFields(Fields $fields, array $data, string $path = '', array $except = [], array $allowed = [], mixed $stored = null): void
    {
        $messages = $this->unknownFields($fields, $data, $path, $except, $allowed, $stored);

        if ($messages !== []) {
            throw new ToolException(implode('; ', array_slice($messages, 0, 10)).(count($messages) > 10 ? sprintf('; and %d more', count($messages) - 10) : ''));
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $except
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function unknownFields(Fields $fields, array $data, string $path, array $except = [], array $allowed = [], mixed $stored = null): array
    {
        $handles = $fields->all()->map(fn (Field $field): string => $field->handle())->diff($except)->values()->all();

        $unknown = array_values(array_filter(
            array_diff(array_keys($data), $handles, $allowed),
            fn (int|string $key) => ! is_array($stored) || ! array_key_exists($key, $stored),
        ));

        $messages = $unknown === [] ? [] : [$this->unknownFieldsMessage($unknown, $handles, $path)];

        foreach (Arr::except($data, $allowed) as $handle => $value) {
            if (in_array($handle, $handles, true)) {
                $messages = [...$messages, ...$this->invalidValue($fields->get($handle), $value, ltrim("{$path}.{$handle}", '.'), is_array($stored) ? ($stored[$handle] ?? null) : null)];
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function invalidValue(Field $field, mixed $value, string $path, mixed $stored): array
    {
        if ($value === null || $this->unchanged($value, $stored)) {
            return [];
        }

        if (TextEdit::isEdit($value) && TextEdit::supports($field)) {
            throw new ToolException(sprintf('%s takes {"find": "...", "replace": "..."} only at the top level or in a set addressed by id — send its new value', $path));
        }

        $fieldtype = $field->fieldtype();

        // Bard extends Replicator, so it has to be matched first.
        if ($fieldtype instanceof Bard) {
            return $this->invalidBard($fieldtype, $value, $path, $stored);
        }

        if ($fieldtype instanceof Replicator) {
            return $this->invalidSets($fieldtype, $value, $path, $stored);
        }

        if ($fieldtype instanceof Grid) {
            return $this->invalidRows($fieldtype, $value, $path, $stored);
        }

        if ($fieldtype instanceof Group) {
            return $this->invalidGroup($fieldtype, $value, $path, $stored);
        }

        if ($fieldtype instanceof Assets) {
            $this->rejectMissingAssets($field, $value, $path, $stored);
        }

        return [];
    }

    private function unchanged(mixed $value, mixed $stored): bool
    {
        return $stored !== null && $this->normalize($value) === $this->normalize($stored);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedById(mixed $stored, mixed $id, string $key): ?array
    {
        if (! is_array($stored) || ! is_scalar($id) || (string) $id === '') {
            return null;
        }

        $matches = array_values(array_filter($stored, fn (mixed $item) => is_array($item) && (string) data_get($item, $key) === (string) $id));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return list<string>
     */
    private function invalidSets(Replicator $fieldtype, mixed $sets, string $path, mixed $stored): array
    {
        if (! is_array($sets) || ! array_is_list($sets)) {
            throw new ToolException("{$path} must be a list of sets, each an object with a type — an object keyed by set id merges into stored sets only through an update that addresses each enclosing set by id");
        }

        $messages = [];

        foreach ($sets as $index => $set) {
            $counterpart = $this->storedById($stored, data_get($set, Sets::idHandle()), Sets::idHandle());

            $messages = [...$messages, ...$this->invalidSet($fieldtype, $set, "{$path}.".$this->setLabel($set, $index), $counterpart)];
        }

        return $messages;
    }

    /**
     * Sets are named by their id where they carry one, as the agent and the
     * validation errors address them, by position otherwise.
     */
    private function setLabel(mixed $set, int $index): string
    {
        $id = is_array($set) ? data_get($set, Sets::idHandle()) : null;

        return is_string($id) && $id !== '' ? $id : (string) $index;
    }

    /**
     * @return list<string>
     */
    private function invalidSet(Replicator $fieldtype, mixed $set, string $path, mixed $stored): array
    {
        if ($this->unchanged($set, $stored)) {
            return [];
        }

        $types = $fieldtype->flattenedSetsConfig()->keys()->sort()->values()->all();
        $type = data_get($set, 'type');

        if (! is_string($type)) {
            throw new ToolException(sprintf('%s must be a set object with a type — valid set types: %s', $path, implode(', ', $types)));
        }

        $stored = data_get($stored, 'type') === $type ? $stored : null;

        if (! in_array($type, $types, true) && $stored === null) {
            $message = sprintf("unknown set type '%s' in %s — valid set types: %s", $type, $path, implode(', ', $types));

            throw new ToolException(($closest = $this->closest($type, $types)) ? "{$message} — did you mean '{$closest}'?" : $message);
        }

        if (array_key_exists('enabled', $set) && ! is_bool($set['enabled'])) {
            throw new ToolException("{$path}.enabled must be true or false");
        }

        return $this->unknownFields($fieldtype->fields($type), $set, $path, allowed: ['type', 'enabled', Sets::idHandle()], stored: $stored);
    }

    /**
     * @return list<string>
     */
    private function invalidBard(Bard $fieldtype, mixed $nodes, string $path, mixed $stored): array
    {
        // An HTML string is fine: preProcess() converts it to ProseMirror.
        if (is_string($nodes)) {
            return [];
        }

        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new ToolException("{$path} must be a list of ProseMirror nodes, or an HTML string");
        }

        $messages = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! is_string(data_get($node, 'type'))) {
                throw new ToolException("{$path}.{$index} must be a ProseMirror node object with a type");
            }

            if ($node['type'] === 'set') {
                $counterpart = $this->storedById($stored, data_get($node, 'attrs.id'), 'attrs.id');

                $messages = [...$messages, ...$this->invalidSet($fieldtype, data_get($node, 'attrs.values'), "{$path}.{$index}.attrs.values", data_get($counterpart, 'attrs.values'))];
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function invalidRows(Grid $fieldtype, mixed $rows, string $path, mixed $stored): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new ToolException("{$path} must be a list of rows — an object keyed by row id merges into stored rows only through an update that addresses each enclosing set by id");
        }

        $messages = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new ToolException("{$path}.{$index} must be a row object keyed by field handle");
            }

            $counterpart = $this->storedById($stored, data_get($row, Sets::idHandle()), Sets::idHandle());

            if (! $this->unchanged($row, $counterpart)) {
                $messages = [...$messages, ...$this->unknownFields($fieldtype->fields($index), $row, "{$path}.".$this->setLabel($row, $index), allowed: [Sets::idHandle()], stored: $counterpart)];
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function invalidGroup(Group $fieldtype, mixed $values, string $path, mixed $stored): array
    {
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new ToolException("{$path} must be an object keyed by field handle");
        }

        return $this->unknownFields($fieldtype->fields(), $values, $path, stored: $stored);
    }

    private function rejectMissingAssets(Field $field, mixed $value, string $path, mixed $stored): void
    {
        $container = $this->assetsFieldContainer($field);

        // An unresolvable container is a blueprint problem, not the agent's.
        if (! $container instanceof AssetContainerContract) {
            return;
        }

        $handle = $container->handle();

        foreach (Arr::wrap($value) as $reference) {
            if (in_array($reference, Arr::wrap($stored), true)) {
                continue;
            }

            $id = is_string($reference) && ! str_contains($reference, '::') ? "{$handle}::{$reference}" : $reference;

            if (! is_string($id) || ! str_starts_with($id, "{$handle}::") || ! Asset::find($id)) {
                throw new ToolException(sprintf(
                    "asset %s not found in container '%s' (%s) — pass a path from assets_list or assets_upload",
                    is_string($reference) ? "'{$reference}'" : json_encode($reference),
                    $handle,
                    $path,
                ));
            }
        }
    }

    /**
     * Mirrors the (protected) Assets::container(): the configured container,
     * or the only one when there is exactly one.
     */
    private function assetsFieldContainer(Field $field): ?AssetContainerContract
    {
        if ($configured = $field->get('container')) {
            return AssetContainer::find($configured);
        }

        $containers = AssetContainer::all();

        return $containers->count() === 1 ? $containers->first() : null;
    }

    private function unprocessable(Field $field): ToolException
    {
        return new ToolException(sprintf(
            "field %s has a value its fieldtype (%s) can't process — send the raw shape the get tools return; blueprints_get shows each field's example",
            $field->handle(),
            $field->type(),
        ));
    }

    /**
     * @param  list<array-key>  $unknown
     * @param  array<int, string>  $handles
     */
    private function unknownFieldsMessage(array $unknown, array $handles, string $path): string
    {
        $suggestions = collect($unknown)
            ->map(fn ($key) => ($closest = $this->closest((string) $key, $handles))
                ? sprintf("did you mean '%s' instead of '%s'?", $closest, $key)
                : null)
            ->filter()
            ->implode(' ');

        sort($handles);

        $message = sprintf(
            'unknown field%s %s%s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            $path === '' ? '' : " in {$path}",
            implode(', ', $handles),
        );

        return $suggestions === '' ? $message : "{$message} — {$suggestions}";
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function closest(string $given, array $candidates): ?string
    {
        $closest = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($given, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $closest = $candidate;
            }
        }

        return $bestDistance <= 3 ? $closest : null;
    }
}
