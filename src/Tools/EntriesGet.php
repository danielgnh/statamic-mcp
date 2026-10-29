<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\LocalizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\PresentsAugmentedData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\SelectsFields;
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

#[Name('entries_get')]
#[Description('Get a single entry by id, or by collection + slug. Returns raw field data by default — the round-trippable shape for entries_update. format=augmented returns rendered values for display only: NEVER send augmented data back into entries_update. Large values are truncated to preview objects unless requested via fields (an array of top-level field handles): Bard and Markdown over 500 bytes, and any other value over 4 KB, a Grid excepted. A large Replicator field (a page builder) comes back as an index of its sets — id, type, and a short preview each — unless requested via fields: e.g. "page_builder" fetches the whole field, "page_builder.<id>" one set (of a Replicator or Grid), as an object keyed by its id that entries_update merges back by id. fields selects blueprint fields, and in raw format any other key the entry stores — augmented-only keys such as permalink are not selectable. On revision-enabled entries, has_working_copy reports whether staged (unpublished) changes exist, and the returned data is the staged working copy when there is one, as in the Control Panel\'s edit form: exactly what entries_update builds on and entries_publish would promote. working_copy: false returns the live entry instead, and source says which one you got. On a collection in more than one site, localizations lists each site you can access with the id and status of the entry there, or null where it has none — add one with entries_localize.')]
#[IsReadOnly]
class EntriesGet extends Tool
{
    use LocalizesEntries;
    use PresentsAugmentedData;
    use ResolvesEntries;
    use ResolvesSites;
    use SelectsFields;

    private const array METADATA = ['updated_at', 'updated_by', 'blueprint'];

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id. Either id, or collection + slug, is required.'),
            'collection' => $schema->string()->description('Collection handle — used with slug when id is omitted.'),
            'slug' => $schema->string()->description('Entry slug — used with collection when id is omitted. When several entries of the site share it, as pages under different parents can, the call fails and lists their ids and URLs.'),
            'site' => $schema->string()->description("Site handle. With an id it must match that entry's own site (omit it otherwise); with collection + slug it selects the localization. Defaults to the default site."),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default): $entry->data(), writable. augmented: rendered values, display only — never writable.'),
            'fields' => $schema->array()->description('Top-level field handles to return in full — a large value listed here skips the preview. handle.id, with a set id from the field\'s index, returns one set of a Replicator or Grid field as an object keyed by its id: the shape the update tool merges by id.'),
            'working_copy' => $schema->boolean()->description('Revision-enabled collections: false returns the live entry instead of the staged working copy, which is the default whenever changes are staged. When nothing is staged, the entry itself is returned either way.'),
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

        $staged = (bool) ($validated['working_copy'] ?? true) && $live->revisionsEnabled() && $live->hasWorkingCopy();

        $entry = $staged ? $live->fromWorkingCopy() : $live;

        // Statamic caches URIs by entry id and the working copy shares the live
        // entry's id, so forget it here for a staged slug, and again below so
        // the staged URI never sticks to the live entry.
        if ($staged) {
            Blink::store('entry-uris')->forget($entry->id());
        }

        $format = $validated['format'] ?? 'raw';
        $blueprint = $entry->blueprint();

        $localization = null;

        if ($format === 'augmented') {
            $data = $this->augmentedData($entry->toAugmentedArray());
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

        [$requestedFields, $selectedSets] = $this->parseFieldSelection(
            array_values($validated['fields'] ?? []),
            $blueprint,
            $format === 'raw' ? array_keys($data) : [],
        );

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }

        $data = $this->withSelectedSets($data, $selectedSets, $blueprint);
        $data = $this->withPreviews($data, $blueprint, $requestedFields);

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
            $response['source'] = $staged ? 'working_copy' : 'entry';
        }

        if ($format === 'augmented') {
            $response['warning'] = 'augmented data is rendered for display — NEVER send it back into entries_update; fetch raw first';
        }

        if ($localization !== null) {
            $response['localization'] = $localization;
        }

        if ($localizations = $this->localizations($user, $live)) {
            $response['localizations'] = $localizations;
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

        // Before the lookup: its error can list other entries of the collection.
        $this->ensurePermission($user, "view {$collection} entries");

        $entries = Entry::query()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->where('site', $site)
            ->get();

        if ($entries->isEmpty()) {
            throw new ToolException(sprintf("entry '%s/%s' not found in site '%s'", $collection, $slug, $site));
        }

        // CP parity: only the URL is unique, so pages under different parents
        // can share a slug — never answer with an arbitrary one of them.
        if ($entries->count() > 1) {
            throw new ToolException(sprintf(
                "%d entries of collection '%s' have slug '%s' in site '%s' — pass the id of the one you mean: %s",
                $entries->count(),
                $collection,
                $slug,
                $site,
                $entries->map(fn (EntryContract $entry) => sprintf('%s (%s)', $entry->id(), $entry->url() ?? 'no URL'))->implode(', '),
            ));
        }

        return $entries->first();
    }
}
