<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Value;

#[Name('entries_get')]
#[Description('Get a single entry by id, or by collection + slug. Returns raw field data by default — the round-trippable shape for entries_update. format=augmented returns rendered values for display only: NEVER send augmented data back into entries_update. Long Bard/rich-text values are truncated to preview objects unless requested via fields (an array of top-level field handles; no nesting in v1). fields selects blueprint fields only — augmented-only keys such as permalink are not selectable. On revision-enabled entries, has_working_copy reports whether staged (unpublished) changes exist; the returned data is always the live entry.')]
#[IsReadOnly]
class EntriesGet extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;

    // Bytes (strlen) of encoded JSON before truncation — byte-based on purpose:
    // it approximates token cost; multibyte characters count per-byte.
    private const int PREVIEW_THRESHOLD = 500;

    // Characters of plain-text preview kept (Str::limit is mb-safe — never cuts mid-character).
    private const int PREVIEW_LENGTH = 300;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id. Either id, or collection + slug, is required.'),
            'collection' => $schema->string()->description('Collection handle — used with slug when id is omitted.'),
            'slug' => $schema->string()->description('Entry slug — used with collection when id is omitted.'),
            'site' => $schema->string()->description("Site handle. With an id it must match that entry's own site (omit it otherwise); with collection + slug it selects the localization. Defaults to the default site."),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default): $entry->data(), writable. augmented: rendered values, display only — never writable.'),
            'fields' => $schema->array()->description('Top-level field handles to return in full — Bard/rich-text fields listed here skip preview truncation.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'id' => 'nullable|string',
                'collection' => 'nullable|string',
                'slug' => 'nullable|string',
                'site' => 'nullable|string',
                'format' => 'nullable|string|in:raw,augmented',
                'fields' => 'nullable|array',
                'fields.*' => 'string',
            ],
            [
                'format.in' => 'format must be one of: raw, augmented.',
            ],
        );

        $user = $this->user($request);
        $entry = $this->resolveEntry($request, $user);

        $collection = $entry->collection()->handle();
        $this->ensurePermission($user, "view {$collection} entries");

        $format = $validated['format'] ?? 'raw';
        $requestedFields = array_values($validated['fields'] ?? []);
        $blueprint = $entry->blueprint();

        $this->assertKnownFields($requestedFields, $blueprint);

        $localization = null;

        if ($format === 'augmented') {
            // Value::jsonSerialize runs a FULL augment — a terms relation would
            // inline whole augmented terms including their reverse entries.
            // shallow() reduces relations to id/title/api_url-style stubs.
            $data = collect((array) $entry->toAugmentedArray())
                ->map(fn ($value) => $value instanceof Value ? $value->shallow() : $value)
                ->all();
        } else {
            // raw: the round-trippable write shape. updated_at/updated_by are
            // Statamic-managed metadata (its own toArray excludes updated_at) —
            // stripped so agents can't round-trip stale values into updates.
            $data = $entry->data()->except(['updated_at', 'updated_by'])->all();

            if ($entry->hasOrigin()) {
                // Walk the whole origin chain: each origin's data() is its OWN
                // values only (chain resolution lives in values(), which also
                // merges the collection cascade — not round-trippable), so a
                // field set only on the root would otherwise vanish here.
                // += keeps the nearest origin's value when levels collide.
                $inherited = [];

                for ($origin = $entry->origin(); $origin !== null; $origin = $origin->origin()) {
                    $inherited += $origin->data()->except(['updated_at', 'updated_by'])->all();
                }

                $inherited = array_diff_key($inherited, $data);

                $localization = [
                    // the DIRECT origin — it's what a severing write detaches from
                    'origin_id' => $entry->origin()->id(),
                    'local_overrides' => array_keys($data),
                    'inherited_from_origin' => array_keys($inherited),
                    'note' => 'inherited fields are shown from the origin — sending one back in entries_update makes it a local override',
                ];

                $data = array_merge($inherited, $data); // disjoint by the diff_key above — local wins there
            }
        }

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }

        $data = $this->withRichTextPreviews($data, $blueprint, $requestedFields);

        $response = [
            'id' => $entry->id(),
            'collection' => $collection,
            'slug' => $entry->slug(),
            'site' => $entry->locale(),
            'status' => $entry->status(),
            'published' => $entry->published(),
            'url' => $entry->url(),
            'format' => $format,
            'data' => $data,
            'cp_edit_url' => $entry->editUrl(),
        ];

        if ($entry->collection()->dated()) {
            $response['date'] = $entry->date()?->toIso8601String();
        }

        // One-key working-copy surfacing (staged values themselves are v1.1):
        // data above is always the LIVE entry — a true here means CP or MCP
        // edits are staged on top of it.
        if ($entry->revisionsEnabled()) {
            $response['has_working_copy'] = $entry->hasWorkingCopy();
        }

        if ($format === 'augmented') {
            $response['warning'] = 'augmented data is rendered for display — NEVER send it back into entries_update; fetch raw first';
        }

        if ($localization !== null) {
            $response['localization'] = $localization;
        }

        return $this->json($response);
    }

    private function resolveEntry(Request $request, UserContract $user): EntryContract
    {
        if ($id = $request->get('id')) {
            return $this->findExposedEntry((string) $id, $user, $request->get('site'));
        }

        $collection = $request->get('collection');
        $slug = $request->get('slug');

        if (! $collection || ! $slug) {
            throw new ToolException('pass id, or collection + slug, to identify the entry');
        }

        $this->ensureExposed('collections', (string) $collection);

        $site = $this->resolveSite($request, $user);

        $entry = Entry::query()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->where('site', $site)
            ->first();

        if (! $entry) {
            throw new ToolException(sprintf("entry '%s/%s' not found in site '%s'", $collection, $slug, $site));
        }

        return $entry;
    }

    /**
     * @param  list<string>  $requestedFields
     */
    private function assertKnownFields(array $requestedFields, Blueprint $blueprint): void
    {
        if ($requestedFields === []) {
            return;
        }

        // 'slug' is v6's auto-injected blueprint field (Collection::ensureEntryBlueprintFields);
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
     * Long Bard/markdown values become {__preview, truncated, note} objects
     * unless explicitly requested via fields (spec §4 row 4).
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
            if (! in_array($field->type(), ['bard', 'markdown'], true)) {
                continue;
            }

            // Augmented values are JsonSerializable wrappers; normalize before measuring.
            $raw = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;

            $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            if (strlen($encoded) <= self::PREVIEW_THRESHOLD) {
                continue;
            }

            $data[$handle] = [
                '__preview' => Str::limit($this->plainText($raw), self::PREVIEW_LENGTH),
                'truncated' => true,
                'note' => sprintf('NOT writable — fetch raw field before editing: entries_get with fields: ["%s"]', $handle),
            ];
        }

        return $data;
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
