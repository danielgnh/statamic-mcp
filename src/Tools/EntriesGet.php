<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\PreviewsRichText;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Fields\Value;

#[Name('entries_get')]
#[Description('Get a single entry by id, or by collection + slug. Returns raw field data by default — the round-trippable shape for entries_update. format=augmented returns rendered values for display only: NEVER send augmented data back into entries_update. Long Bard/rich-text values are truncated to preview objects unless requested via fields (an array of top-level field handles; no nesting in v1). fields selects blueprint fields only — augmented-only keys such as permalink are not selectable. On revision-enabled entries, has_working_copy reports whether staged (unpublished) changes exist, and the returned data is the live entry unless working_copy is true: then it is the staged working copy, exactly what entries_publish would promote, and source says which one you got.')]
#[IsReadOnly]
class EntriesGet extends Tool
{
    use PreviewsRichText;
    use ResolvesEntries;
    use ResolvesSites;

    private const METADATA = ['updated_at', 'updated_by', 'blueprint'];

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
            'working_copy' => $schema->boolean()->description('Return the staged working copy instead of the live entry (revision-enabled collections) — review it before entries_publish. When nothing is staged, the entry itself is returned.'),
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
                'working_copy' => 'nullable|boolean',
            ],
            [
                'format.in' => 'format must be one of: raw, augmented.',
            ],
        );

        $user = $this->user($request);
        $live = $this->resolveEntry($request, $user);

        $collection = $live->collection()->handle();
        $this->ensurePermission($user, "view {$collection} entries");

        $staged = data_get($validated, 'working_copy') && $live->revisionsEnabled() && $live->hasWorkingCopy();

        $entry = $staged ? $live->fromWorkingCopy() : $live;

        // Statamic caches URIs by entry id and the working copy shares the live
        // entry's id, so forget it here for a staged slug, and again below so
        // the staged URI never sticks to the live entry.
        if ($staged) {
            Blink::store('entry-uris')->forget($entry->id());
        }

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
            // raw: the round-trippable write shape. updated_at/updated_by and
            // blueprint are Statamic-managed metadata (its own toArray excludes
            // updated_at) — stripped so agents can't round-trip stale values
            // into updates. The blueprint comes back at the top level instead.
            $data = $entry->data()->except(self::METADATA)->all();

            if ($entry->hasOrigin()) {
                // Walk the whole origin chain: each origin's data() is its OWN
                // values only (chain resolution lives in values(), which also
                // merges the collection cascade — not round-trippable), so a
                // field set only on the root would otherwise vanish here.
                // += keeps the nearest origin's value when levels collide.
                $inherited = [];

                for ($origin = $entry->origin(); $origin !== null; $origin = $origin->origin()) {
                    $inherited += $origin->data()->except(self::METADATA)->all();
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
            'blueprint' => $blueprint->handle(),
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

        if ($live->revisionsEnabled()) {
            $response['has_working_copy'] = $live->hasWorkingCopy();
        }

        if (filled(data_get($validated, 'working_copy'))) {
            $response['source'] = $staged ? 'working_copy' : 'entry';
        }

        if ($format === 'augmented') {
            $response['warning'] = 'augmented data is rendered for display — NEVER send it back into entries_update; fetch raw first';
        }

        if ($localization !== null) {
            $response['localization'] = $localization;
        }

        if ($staged) {
            Blink::store('entry-uris')->forget($entry->id());
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
}
