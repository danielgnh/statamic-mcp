<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Statamic\Contracts\Entries\Entry as EntryContract;

#[Name('entries_delete')]
#[Description('Permanently delete an entry by id. Deleting an origin also deletes all of its localizations (this requires site access to every localization\'s site); the response lists everything that was removed. On revision-enabled collections the entry\'s revision and working-copy files stay on disk as orphans (the Control Panel behaves the same way). This cannot be undone.')]
#[IsDestructive]
class EntriesDelete extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->deletesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureDeletesEnabled();

        $validated = $request->validate(['id' => 'required|string']);

        $user = $this->user($request);

        // Exposure + site-access (the entry's own locale) in one place —
        // never look up by bare id.
        $entry = $this->findExposedEntry($validated['id'], $user);

        $collection = $entry->collection()->handle();

        $this->ensurePermission($user, "delete {$collection} entries");

        // Vendor Entry::delete() refuses origins that still have localizations
        // (it throws) — v6 never cascades implicitly. The CP resolves this by
        // asking, and only offers its cascading "Delete" choice to users with
        // access to every descendant's site. Mirror that: gate every
        // localization site BEFORE deleting anything, then cascade explicitly.
        $descendants = $entry->descendants();

        $descendants->map->locale()->unique()->each(
            fn (string $site) => $this->ensureSiteAccess($user, $site)
        );

        $deleted = $descendants
            ->map(fn (EntryContract $localization) => [
                'id' => $localization->id(),
                'site' => $localization->locale(),
                'slug' => $localization->slug(),
            ])
            ->values()
            ->all();

        if ($descendants->isNotEmpty()) {
            $cascadeFailure = null;

            try {
                $entry->deleteDescendants();
            } catch (\Exception $cascadeFailure) {
                // A cancelled deeper localization leaves a survivor that makes
                // its PARENT's delete() throw vendor's raw 'Cannot delete an
                // entry with localizations.' mid-sweep — same root cause as a
                // cancelled direct child (which deleteDescendants() silently
                // swallows), so both shapes route into the survivor check below.
            }

            // fresh() past the Blink cache: it goes stale when the cascade
            // aborts mid-sweep (deleteDescendants() only forgets it on
            // completion), and deleted entries must not be named as survivors.
            $survivors = $entry->descendants()->map->fresh()->filter();

            if ($survivors->isNotEmpty()) {
                if ($cascadeFailure) {
                    // The agent gets the survivor ToolException below; the
                    // swallowed vendor throw still goes to the host app's
                    // exception reporter so operators see the root cause.
                    report($cascadeFailure);
                }

                $survivorIds = $survivors->map->id()->values()->all();

                $alreadyDeleted = collect($deleted)->reject(
                    fn (array $localization) => in_array($localization['id'], $survivorIds, true)
                );

                throw new ToolException(sprintf(
                    'localizations could not be deleted (a listener may have cancelled, or new localizations appeared) — still present: %s. The origin entry was not deleted%s.',
                    $survivors->map(fn (EntryContract $localization) => $localization->locale().' => '.$localization->id())->values()->implode('; '),
                    $alreadyDeleted->isEmpty()
                        ? ' and nothing else was'
                        : sprintf('; %d localization%s already deleted', $alreadyDeleted->count(), $alreadyDeleted->count() === 1 ? ' was' : 's were'),
                ));
            }

            if ($cascadeFailure) {
                // Nothing survived, so it wasn't a cancellation — don't mask
                // an unknown failure behind a false success.
                throw $cascadeFailure;
            }
        }

        // delete() returns false when an EntryDeleting listener cancels
        // (approval addons do this) — never report success for it, same rule
        // as save().
        if (! $entry->delete()) {
            throw new ToolException($deleted === []
                ? 'the delete was cancelled by a listener on this site — the entry was not deleted'
                : 'the delete was cancelled by a listener on this site — the origin entry was not deleted, but its localizations were already deleted');
        }

        // Outcome statement only — deliberately NO cp_edit_url: the deleted
        // entry's CP page would 404 (amended spec exception).
        $payload = [
            'id' => $entry->id(),
            'collection' => $collection,
            'slug' => $entry->slug(),
            'site' => $entry->locale(),
            'result' => 'deleted — this cannot be undone',
        ];

        if ($deleted !== []) {
            $payload['deleted_localizations'] = $deleted;
            $payload['result'] = sprintf(
                'deleted, along with %d localization%s — this cannot be undone',
                count($deleted),
                count($deleted) === 1 ? '' : 's',
            );
        }

        return $this->json($payload);
    }
}
