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

#[Name('entries_publish')]
#[Description('Make an entry live by id. Requires the publish permission for the collection, or \'publish other authors {collection} entries\' for an entry you are not an author of when the blueprint has an author field. On revision-enabled collections this promotes the staged working copy (or the draft itself when nothing is staged) and records a publish revision attributed to you — the same flow as the Control Panel\'s Publish button. An already-published entry with nothing staged is a no-op. This is the only tool that publishes: entries_create and entries_update never change publish state.')]
#[IsIdempotent]
class EntriesPublish extends Tool
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

        $promotingWorkingCopy = $entry->revisionsEnabled() && $entry->hasWorkingCopy();

        if ($entry->published() && ! $promotingWorkingCopy) {
            return $this->json([
                'id' => $entry->id(),
                'result' => 'no-op — already published, nothing staged',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        // CP parity (PublishedEntriesController@store, 6.x): Publishable::
        // publish() flips the flag on plain collections and, with revisions,
        // publishes the working copy with an attributed 'publish' revision.
        // Both paths return false when a listener cancels the save.
        $live = $entry->publish(['user' => $user, 'message' => 'via MCP entries_publish']);

        if ($live === false) {
            throw new ToolException('the publish was cancelled by a listener on this site — the entry is not live');
        }

        return $this->json([
            'id' => $live->id(),
            'slug' => $live->slug(),
            'status' => $live->status(),
            'url' => $live->url(),
            ...$this->liveness($live, $promotingWorkingCopy ? self::LIVENESS_PUBLISHED_WORKING_COPY : self::LIVENESS_PUBLISHED),
        ]);
    }
}
