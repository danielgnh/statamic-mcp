<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('entries_unpublish')]
#[Description('Take a live entry offline by id. Requires the publish permission for the collection (Statamic has no separate unpublish permission — the Control Panel gates both on publish), or \'publish other authors {collection} entries\' for an entry you are not an author of when the blueprint has an author field. On revision-enabled collections this records an unpublish revision attributed to you; a staged working copy is applied to the entry and cleared, exactly like the Control Panel. An entry that is already a draft is a no-op.')]
#[IsIdempotent]
class EntriesUnpublish extends Tool
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
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $validated = $request->validate(['id' => 'required|string']);

        $user = $this->user($request);

        $entry = $this->findExposedEntry($validated['id'], $user);

        $this->ensureEntryPermission($user, 'publish', $entry);

        if (! $entry->published()) {
            return $this->json([
                'id' => $entry->id(),
                'result' => 'no-op — already a draft',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        $applyingWorkingCopy = $entry->revisionsEnabled() && $entry->hasWorkingCopy();

        // CP parity (PublishedEntriesController@destroy, 6.x), spelled out
        // because Publishable::unpublish() returns the entry even when a
        // listener cancels the save on a plain collection.
        if ($entry->revisionsEnabled()) {
            $draft = $entry->unpublishWorkingCopy(['user' => $user, 'message' => 'via MCP entries_unpublish']);
        } else {
            $draft = $entry->published(false)->save() ? $entry : false;
        }

        if ($draft === false) {
            throw new ToolException('the unpublish was cancelled by a listener on this site — the entry is still live');
        }

        return $this->json([
            'id' => $draft->id(),
            'slug' => $draft->slug(),
            'status' => $draft->status(),
            'url' => $draft->url(),
            ...$this->liveness($draft, $applyingWorkingCopy ? self::LIVENESS_UNPUBLISHED_WORKING_COPY : self::LIVENESS_UNPUBLISHED),
        ]);
    }
}
