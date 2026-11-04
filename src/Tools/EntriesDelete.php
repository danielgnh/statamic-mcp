<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry;

#[Name('entries_delete')]
#[Description('Permanently delete an entry by id. When the blueprint has an author field, deleting an entry you are not an author of needs \'delete other authors {collection} entries\'. Deleting an origin also deletes all of its localizations (this requires site access to every localization\'s site); the response lists everything that was removed. On revision-enabled collections the entry\'s revision and working-copy files stay on disk as orphans (the Control Panel behaves the same way). This cannot be undone.')]
#[IsDestructive]
class EntriesDelete extends Tool
{
    use AuthorizesEntries;
    use ResolvesEntries;
    use ResolvesSites;

    #[\Override]
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
        $this->ensureDeletesEnabled();

        $validated = $request->validate(['id' => 'required|string']);

        $user = $this->user($request);

        $entry = $this->findExposedEntry($validated['id'], $user);

        $collection = $entry->collection()->handle();

        $this->ensureEntryPermission($user, 'delete', $entry);

        /** @var Collection<int, Entry> $descendants */
        $descendants = $entry->descendants();

        $descendants->map->locale()->unique()->each(
            fn (string $site) => $this->ensureSiteAccess($user, $site)
        );

        $deleted = $descendants
            ->map(fn (Entry $localization) => [
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
            } catch (Exception $e) {
                $cascadeFailure = $e;
            }

            $survivors = $entry->descendants()->map->fresh()->filter();

            if ($survivors->isNotEmpty()) {
                if ($cascadeFailure) {
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
                throw $cascadeFailure;
            }
        }

        if (! $entry->delete()) {
            throw new ToolException($deleted === []
                ? 'the delete was cancelled by a listener on this site — the entry was not deleted'
                : 'the delete was cancelled by a listener on this site — the origin entry was not deleted, but its localizations were already deleted');
        }

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
