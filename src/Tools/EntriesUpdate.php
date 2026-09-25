<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\LocalizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\NormalizesEntryInput;
use Danielgnh\StatamicMcp\Tools\Concerns\PlacesEntries;
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
use Statamic\Contracts\Structures\CollectionTree;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

#[Name('entries_update')]
#[Description('Update an entry with a shallow top-level merge of raw field data: nested structures (Bard, arrays) are replaced wholesale, never deep-merged — always send the complete new value for a nested field. Explicit null clears a field (stores a local null); resetting a field to inherit from its origin localization is not supported in v1. Publish state is never changed here — that is entries_publish / entries_unpublish. Without revisions, re-dating a published entry on a collection whose date_behavior is private (see statamic_overview) can schedule or expire it; status and result report the outcome. On revision-enabled collections, edits to a published entry are staged as a working copy attributed to you (the live entry stays unchanged — promote it with entries_publish); when a working copy already exists the edit rebases onto it (created vs amended is stated in the result), and unpublished drafts are saved directly. site is a selector only — it must match the entry\'s own site; entries_localize adds an entry to another site. On a localization, data may only name fields the blueprint marks localizable: every other field shows the origin\'s value in every site, so change it on the origin entry. If the merged result equals the current entry, nothing is saved. When the blueprint has an author field, editing an entry you are not an author of needs \'edit other authors {collection} entries\', and so does changing its author. parent moves the entry, with its children, in a structured collection\'s tree: pass the id of an entry of the same collection and site to make it that entry\'s last child, or "" for the top level; omitted or null, the entry stays where it is. Moving needs reorder {collection} entries, like the CP\'s tree, on top of the edit permission and its author rule. A move saves the live tree at once, also when the data goes to a working copy: working copies never stage tree position. The result reports the move under move and the new parent under parent.')]
#[IsIdempotent]
class EntriesUpdate extends Tool
{
    use AuthorizesEntries;
    use ComparesPatchData;
    use LocalizesEntries;
    use NormalizesEntryInput;
    use PlacesEntries;
    use ResolvesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'data' => $schema->object()->description('Raw field values to merge over the current top-level data. Unknown keys are rejected; null clears a field. May be an empty object when only changing slug, date, or parent.')->required(),
            'slug' => $schema->string()->description('New slug.'),
            'date' => $schema->string()->description('New date, dated collections only: 2026-07-09, or 2026-07-09T15:30:00+02:00 with a time. A time without an offset is read in server.timezone from statamic_overview.'),
            'site' => $schema->string()->description("Selector only: must match the entry's own site, or be omitted. To add the entry to another site, call entries_localize."),
            'parent' => $schema->string()->description('Entry id to move this entry under, or "" for the top level. Omit it, or send null, to leave the entry where it is.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $this->rejectPublishedArgument($request, 'entries_update');

        $validated = $request->validate(
            [
                'id' => 'required|string',
                // present (not required): Laravel's 'required' fails on [],
                // and a slug, date, or parent-only update sends an empty object.
                'data' => 'present|array',
                'slug' => 'nullable|string',
                'date' => 'nullable|string',
                'site' => 'nullable|string',
                'parent' => 'nullable|string',
            ],
            ['data.present' => 'Pass data to merge (may be an empty object when only changing slug, date, or parent).'],
        );

        $user = $this->user($request);

        // Exposure + site-match + site-access in one place — never look up by bare id.
        $entry = $this->findExposedEntry($validated['id'], $user, $validated['site'] ?? null);

        $collection = $entry->collection();
        $collectionHandle = $collection->handle();

        $this->ensureEntryPermission($user, 'edit', $entry);

        // updated_at/updated_by are Statamic-managed metadata (entries_get
        // strips them from raw output, but stale copies may live in agent
        // context) — silently ignored, never merged or treated as a change.
        // So is the entry's own blueprint; naming another one stays an error.
        $data = collect((array) $validated['data'])
            ->except(['updated_at', 'updated_by'])
            ->reject(fn (mixed $value, int|string $key) => $key === 'blueprint' && $value === $entry->blueprint()->handle())
            ->all();

        $this->rejectPreviewObjects($data, 'entries_get');

        $this->rejectAmbiguousDataKeys($data, $collection->dated());

        $blueprint = $entry->blueprint();
        $this->rejectUnknownKeys($blueprint, $data);

        if ($entry->hasOrigin()) {
            $this->rejectUnlocalizableFields($entry->origin(), $blueprint, $data);
        }

        $date = $this->resolveDate($validated['date'] ?? null, $entry);

        $move = $this->resolveMove($validated['parent'] ?? null, $entry, $user);

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

        $this->ensureAuthorUnchanged($user, $basis, $data);

        $current = $basis->data()->all();

        $slug = $this->resolveSlug($validated['slug'] ?? null, $entry);

        // The injected date field on dated collections is required — satisfy
        // it with the effective Carbon, which preProcess() turns into the
        // date picker's shape. Slug likewise: entries never store it in
        // data, so a blueprint that marks slug required must be fed the
        // effective value (the new slug, or the entry's current one).
        // Replacements mirror the CP's update path, so unique_entry_value
        // excludes this entry itself. A localization validates with the
        // values it inherits under its own, as in the CP's form, so a partial
        // patch never false-fails a required field — only its data is stored.
        $values = [
            ...($basis->hasOrigin() ? $basis->origin()->values()->all() : []),
            ...$current,
            ...$data,
            'slug' => $slug ?? $basis->slug(),
        ];

        if ($collection->dated()) {
            $values['date'] = $date ?? $basis->date();
        }

        $merged = array_merge($current, $this->processAgainstBlueprint(
            $blueprint,
            $values,
            array_keys($data),
            ['id' => $entry->id(), 'collection' => $collectionHandle, 'site' => $entry->locale()],
        ));

        // Strict compare over normalized values: assoc key order is
        // irrelevant (sorted recursively), but types matter — loose == would
        // juggle null == '' and '1' == 1 into false no-ops, so an explicit
        // null could never clear a falsy field and the write would be
        // silently dropped.
        $dirty = $this->normalize($merged) !== $this->normalize($current)
            || ($slug !== null && $slug !== $basis->slug())
            || ($date instanceof Carbon && ! $date->equalTo($basis->date()));

        if (! $dirty) {
            if ($move !== null && $move['from'] !== $move['to']) {
                $this->ensureUniqueUriAfter($entry, $move);
            }

            $placement = $this->applyMove($entry, $move, $workingCopy);

            if ($move !== null && $move['from'] !== $move['to']) {
                return $this->json([
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'status' => $entry->status(),
                    'url' => $entry->url(),
                    'result' => 'moved — data unchanged, nothing else saved',
                    ...$placement,
                    'cp_edit_url' => $entry->editUrl(),
                ]);
            }

            return $this->json([
                'id' => $entry->id(),
                'result' => $amending
                    ? 'no-op — merged result equals the staged working copy; nothing saved, working copy unchanged'
                    : 'no-op — merged result equals the current entry; nothing saved, no revision created',
                ...$placement,
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

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

        $this->ensureUniqueUriAfter($target, $move);

        return $workingCopy
            ? $this->persistWorkingCopy($target, $user, $amending, $collection, $move)
            : $this->persistLive($entry, $user, $collection, $move);
    }

    /**
     * @param  array{from: ?string, to: ?string}|null  $move  from resolveMove()
     */
    private function persistWorkingCopy(EntryContract $target, UserContract $user, bool $amending, CollectionContract $collection, ?array $move): Response
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

        $placement = $this->applyMove($target, $move, workingCopy: true);

        // Statamic caches URIs by entry id and the working copy shares the
        // live entry's id, so forget it for the staged URL, and again after
        // so the staged URL never sticks to the live entry.
        Blink::store('entry-uris')->forget($target->id());

        $payload = [
            'id' => $target->id(),
            'slug' => $target->slug(),
            'status' => $target->status(),
            'url' => $target->url(),
            ...$this->liveness($target, $amending ? self::LIVENESS_WORKING_COPY_AMENDED : self::LIVENESS_WORKING_COPY),
        ];

        Blink::store('entry-uris')->forget($target->id());

        if ($collection->dated()) {
            $payload['date'] = $target->date()?->toIso8601String();
        }

        return $this->json([...$payload, ...$placement]);
    }

    /**
     * @param  array{from: ?string, to: ?string}|null  $move  from resolveMove()
     */
    private function persistLive(EntryContract $entry, UserContract $user, CollectionContract $collection, ?array $move): Response
    {
        // CP parity: updates refresh updated_by/updated_at. save() returns
        // false when an EntrySaving listener cancels (approval addons do
        // this) — never report success for it.
        if (! $entry->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener on this site — the entry was not updated');
        }

        $placement = $this->applyMove($entry, $move, workingCopy: false);

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->entryLiveness($entry, $entry->published() ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json([...$payload, ...$placement]);
    }

    /**
     * The move parent asks for, or null when it asks for none. A null parent
     * is an omitted one, as for every other top-level parameter, so a client
     * that sends null for each unset parameter never moves an entry.
     *
     * @return array{from: ?string, to: ?string}|null
     */
    private function resolveMove(?string $parent, EntryContract $entry, UserContract $user): ?array
    {
        if ($parent === null) {
            return null;
        }

        $collection = $entry->collection();
        $tree = $this->placementTree($collection, $entry->locale()) ?? throw $this->cannotNest($collection);

        // CP parity: the collection tree view saves moves under this
        // permission (CollectionPolicy::reorder), not under edit.
        $this->ensurePermission($user, "reorder {$collection->handle()} entries");

        $page = $tree->find($entry->id());
        $current = $page->parent();

        return [
            'from' => $current && ! $current->isRoot() ? $current->id() : null,
            'to' => $this->resolveParent($parent, $tree, $page)?->id(),
        ];
    }

    /**
     * @param  array{from: ?string, to: ?string}|null  $move  from resolveMove()
     */
    private function ensureUniqueUriAfter(EntryContract $entry, ?array $move): void
    {
        $this->ensureUniqueUri(
            $entry,
            $this->placementTree($entry->collection(), $entry->locale()),
            $move === null ? $entry->parent()?->id() : $move['to'],
        );
    }

    /**
     * Tree position is not part of an entry's revisions: like the CP's tree
     * view, a move saves the live tree at once, whatever the data does.
     *
     * @param  array{from: ?string, to: ?string}|null  $move
     * @return array<string, mixed>
     */
    private function applyMove(EntryContract $entry, ?array $move, bool $workingCopy): array
    {
        if ($move === null) {
            return [];
        }

        ['from' => $from, 'to' => $to] = $move;

        if ($from === $to) {
            return ['parent' => $to, 'move' => $to === null ? 'no-op — already at the top level' : "no-op — already under '{$to}'"];
        }

        if (! $this->saveTreeChange($entry->collection(), $entry->locale(), fn (CollectionTree $tree) => $tree->move($entry->id(), $to))) {
            throw new ToolException('the move was cancelled by a listener on this site — the entry was not moved, and any other change in this update was saved');
        }

        $moved = $to === null ? 'moved to the top level' : "moved under '{$to}'";

        return [
            'parent' => $to,
            'move' => $workingCopy ? "{$moved} — in the live tree at once; working copies do not stage tree position" : $moved,
        ];
    }

    private function resolveDate(?string $date, EntryContract $entry): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        // Symmetry with the slug path: an empty value is an error, never a
        // silent ignore (Carbon::parse('') would quietly mean "now").
        if (trim($date) === '') {
            throw new ToolException('date is empty — pass e.g. 2026-07-09 or 2026-07-09T15:30:00+02:00, or omit date');
        }

        if (! $entry->collection()->dated()) {
            throw new ToolException(sprintf(
                "collection '%s' is not dated — omit date",
                $entry->collection()->handle(),
            ));
        }

        // CP parity: a localization inherits a non-localizable date, and
        // publishing a working copy would silently drop one staged here.
        if ($entry->hasOrigin() && ! $entry->blueprint()->field('date')?->isLocalizable()) {
            throw new ToolException(sprintf(
                "this localization inherits its date from entry '%s' — change the date there, or omit date",
                $entry->origin()->id(),
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
