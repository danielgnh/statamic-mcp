<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\NormalizesEntryInput;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

#[Name('entries_update')]
#[Description('Update an entry with a shallow top-level merge of raw field data: nested structures (Bard, arrays) are replaced wholesale, never deep-merged — always send the complete new value for a nested field. Explicit null clears a field (stores a local null); resetting a field to inherit from its origin localization is not supported in v1. Publish state is untouched unless published is sent; changing it in either direction requires the publish permission. On revision-enabled collections, edits to a published entry are staged as a working copy attributed to you (the live entry stays unchanged — publish the working copy from the Control Panel); when a working copy already exists the edit rebases onto it (created vs amended is stated in the result), unpublished drafts are saved directly, and any explicit published value is rejected. site is a selector only — it must match the entry\'s own site and never creates or moves localizations. If the merged result equals the current entry, nothing is saved.')]
#[IsIdempotent]
class EntriesUpdate extends Tool
{
    use ComparesPatchData;
    use NormalizesEntryInput;
    use ResolvesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'data' => $schema->object()->description('Raw field values to merge over the current top-level data. Unknown keys are rejected; null clears a field. May be an empty object when only changing slug, date, or published.')->required(),
            'slug' => $schema->string()->description('New slug.'),
            'date' => $schema->string()->description('New date (e.g. 2026-07-09 or 2026-07-09 15:30) — dated collections only.'),
            'published' => $schema->boolean()->description('Omit to leave publish state untouched. Changing it requires the publish permission for the collection. Rejected entirely on revision-enabled collections.'),
            'site' => $schema->string()->description("Selector only: must match the entry's own site, or be omitted."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $validated = $request->validate(
            [
                'id' => 'required|string',
                // present (not required): Laravel's 'required' fails on [],
                // and a slug/date/published-only update sends an empty object.
                'data' => 'present|array',
                'slug' => 'nullable|string',
                'date' => 'nullable|string',
                'published' => 'nullable|boolean',
                'site' => 'nullable|string',
            ],
            ['data.present' => 'Pass data to merge (may be an empty object when only changing slug, date, or published).'],
        );

        $user = $this->user($request);

        // Exposure + site-match + site-access in one place — never look up by bare id.
        $entry = $this->findExposedEntry($validated['id'], $user, $validated['site'] ?? null);

        $collection = $entry->collection();
        $collectionHandle = $collection->handle();

        $this->ensurePermission($user, "edit {$collectionHandle} entries");

        // updated_at/updated_by are Statamic-managed metadata (entries_get
        // strips them from raw output, but stale copies may live in agent
        // context) — silently ignored, never merged or treated as a change.
        $data = collect((array) $validated['data'])->except(['updated_at', 'updated_by'])->all();

        $this->rejectPreviewObjects($data, 'entries_get');

        $this->rejectAmbiguousDataKeys($data, $collection->dated());

        $blueprint = $entry->blueprint();
        $this->rejectUnknownKeys($blueprint, $data);

        $date = $this->resolveDate($validated['date'] ?? null, $entry);

        $published = isset($validated['published']) ? (bool) $validated['published'] : null;

        // On revision collections publish state is CP-owned: ANY explicit
        // published value — true or false, even same-state — is rejected;
        // publishing goes through the CP's revision flow. Sits
        // above the publish gate so the rejection wins over a denial.
        if ($published !== null && $entry->revisionsEnabled()) {
            throw new ToolException(sprintf(
                "collection '%s' uses revisions — publish/unpublish from the Control Panel, not via entries_update",
                $collectionHandle,
            ));
        }

        if ($published !== null && $published !== $entry->published()) {
            // Any publish-state transition is gated on 'publish' — the CP's
            // unpublish route authorizes the same ability
            // (v6 has no separate unpublish permission).
            $this->ensurePermission($user, "publish {$collectionHandle} entries");
        }

        // Routing is snapshotted from the LIVE entry BEFORE any rebase:
        // fromWorkingCopy() restores the staged published attribute into the
        // basis, and publish state must stay keyed to what is actually live.
        $workingCopy = $entry->revisionsEnabled() && $entry->published();
        $amending = $workingCopy && $entry->hasWorkingCopy();

        // CP parity (EntriesController@update, 6.x: $entry = $entry->
        // fromWorkingCopy() before touching data): when a working copy is
        // already staged, edits rebase onto it. fromWorkingCopy() hydrates a
        // clone (makeFromRevision), so the live Stache instance stays pristine.
        $basis = $amending ? $entry->fromWorkingCopy() : $entry;

        $current = $basis->data()->all();
        $merged = array_merge($current, $data);

        $slug = $this->resolveSlug($validated['slug'] ?? null, $entry);

        // Strict compare over normalized values: assoc key order is
        // irrelevant (sorted recursively), but types matter — loose == would
        // juggle null == '' and '1' == 1 into false no-ops, so an explicit
        // null could never clear a falsy field and the write would be
        // silently dropped.
        $dirty = $this->normalize($merged) !== $this->normalize($current)
            || ($slug !== null && $slug !== $basis->slug())
            || ($date instanceof Carbon && ! $date->equalTo($basis->date()))
            || ($published !== null && $published !== $entry->published());

        if (! $dirty) {
            return $this->json([
                'id' => $entry->id(),
                'result' => $amending
                    ? 'no-op — merged result equals the staged working copy; nothing saved, working copy unchanged'
                    : 'no-op — merged result equals the current entry; nothing saved, no revision created',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        // The injected date field on dated collections is required — satisfy
        // it with a Carbon (Statamic\Rules\DateFieldtype accepts Carbon,
        // rejects plain strings). Slug likewise: entries never store it in
        // data, so a blueprint that marks slug required must be fed the
        // effective value (the new slug, or the entry's current one).
        // Replacements mirror the CP's update path, so unique_entry_value
        // excludes this entry itself.
        $values = [...$merged, 'slug' => $slug ?? $basis->slug()];

        if ($collection->dated()) {
            $values['date'] = $date ?? $basis->date();
        }

        $this->validateAgainstBlueprint(
            $blueprint,
            $values,
            ['id' => $entry->id(), 'collection' => $collectionHandle, 'site' => $entry->locale()],
        );

        // Stage on the rebased clone when amending, on a fresh clone of live
        // when creating the first working copy — the live Stache instance
        // must stay pristine, it is never saved on the working-copy path.
        $target = match (true) {
            $amending => $basis, // already a clone hydrated from the staged copy
            $workingCopy => clone $entry,
            default => $entry,
        };

        $target->data($merged);

        if ($slug !== null) {
            $target->slug($slug);
        }

        if ($date instanceof Carbon) {
            $target->date($date);
        }

        return $workingCopy
            ? $this->persistWorkingCopy($target, $user, $amending, $collection)
            : $this->persistLive($entry, $published, $user, $collection);
    }

    /**
     * Revision-enabled published entry: stage the edit as a working copy, the
     * live entry is never saved.
     */
    private function persistWorkingCopy(EntryContract $target, UserContract $user, bool $amending, CollectionContract $collection): Response
    {
        // CP parity (EntriesController@update, 6.x): makeWorkingCopy()
        // snapshots the in-memory attributes set above — the live entry is
        // NEVER saved. Revision::save() returns false when a RevisionSaving
        // listener cancels; nothing was persisted then.
        $saved = $target->makeWorkingCopy()
            ->user($user)
            ->message('via MCP entries_update')
            ->save();

        if (! $saved) {
            throw new ToolException('the working copy save was cancelled by a listener on this site — nothing was saved');
        }

        $payload = [
            'id' => $target->id(),
            'slug' => $target->slug(),
            'status' => $target->status(),
            'url' => $target->url(),
            ...$this->liveness($target, $amending ? self::LIVENESS_WORKING_COPY_AMENDED : self::LIVENESS_WORKING_COPY),
        ];

        if ($collection->dated()) {
            $payload['date'] = $target->date()?->toIso8601String();
        }

        return $this->json($payload);
    }

    /**
     * Draft or non-revision collection: write straight to the live entry.
     */
    private function persistLive(EntryContract $entry, ?bool $published, UserContract $user, CollectionContract $collection): Response
    {
        if ($published !== null) {
            $entry->published($published);
        }

        // CP parity: updates refresh updated_by/updated_at. save() returns
        // false when an EntrySaving listener cancels (approval addons do
        // this) — never report success for it.
        if (! $entry->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener on this site — the entry was not updated');
        }

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $entry->published() ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json($payload);
    }

    private function resolveDate(?string $date, EntryContract $entry): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        // Symmetry with the slug path: an empty value is an error, never a
        // silent ignore (Carbon::parse('') would quietly mean "now").
        if (trim($date) === '') {
            throw new ToolException('date is empty — pass e.g. 2026-07-09 or 2026-07-09 15:30, or omit date');
        }

        if (! $entry->collection()->dated()) {
            throw new ToolException(sprintf(
                "collection '%s' is not dated — omit date",
                $entry->collection()->handle(),
            ));
        }

        return $this->parseEntryDate($date);
    }

    /**
     * The normalized new slug, or null when none was sent. Entry::save()
     * re-normalizes through Routable::slug() with the site's language — run
     * the exact same call here so the no-op comparison and the collision
     * check both see what will actually be persisted.
     */
    private function resolveSlug(?string $slug, EntryContract $entry): ?string
    {
        if ($slug === null) {
            return null;
        }

        $normalized = Str::slug($slug, '-', Site::get($entry->locale())->lang());

        if ($normalized === '') {
            throw new ToolException(sprintf("slug '%s' normalizes to an empty string — pass a usable slug", $slug));
        }

        // Its own slug is never a collision — only a changed slug can collide,
        // and the existing holder of the old slug is this entry itself.
        if ($normalized === $entry->slug()) {
            return $normalized;
        }

        $existing = Entry::query()
            ->where('collection', $entry->collection()->handle())
            ->where('slug', $normalized)
            ->where('site', $entry->locale())
            ->first();

        if ($existing && $existing->id() !== $entry->id()) {
            throw new ToolException(sprintf(
                "slug '%s' already exists in collection '%s' (site '%s') as entry '%s'",
                $normalized,
                $entry->collection()->handle(),
                $entry->locale(),
                $existing->id(),
            ));
        }

        return $normalized;
    }
}
