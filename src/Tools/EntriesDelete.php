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
#[Description('Permanently delete an entry by id. Deleting an origin also deletes all of its localizations (this requires site access to every localization\'s site); the response lists everything that was removed. This cannot be undone.')]
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
            $entry->deleteDescendants();

            // deleteDescendants() ignores per-entry cancellations (an
            // EntryDeleting listener returning false); a survivor would make
            // the origin's own delete() throw — report it cleanly instead.
            if ($entry->descendants()->isNotEmpty()) {
                throw new ToolException(
                    'a listener on this site cancelled deleting a localization — the origin entry was not deleted (other localizations may already be gone)'
                );
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
