<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('entries_publish')]
#[Description('Make an entry live by id. Requires the publish permission for the collection. On revision-enabled collections this promotes the staged working copy (or the draft itself when nothing is staged) and records a publish revision attributed to you — the same flow as the Control Panel\'s Publish button. On a dated collection whose date_behavior.future is private (see statamic_overview), an entry dated in the future is scheduled instead: status and result say scheduled, and Statamic takes it live on that date with no further call. To schedule a post, set its date with entries_create or entries_update, then publish it. An already-published entry with nothing staged is a no-op. This is the only tool that publishes: entries_create and entries_update never change publish state.')]
#[IsIdempotent]
class EntriesPublish extends Tool
{
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

        $collection = $entry->collection()->handle();

        $this->ensurePermission($user, "publish {$collection} entries");

        $promotingWorkingCopy = $entry->revisionsEnabled() && $entry->hasWorkingCopy();

        if ($entry->published() && ! $promotingWorkingCopy) {
            return $this->json([
                'id' => $entry->id(),
                'status' => $entry->status(),
                'result' => "no-op — already {$entry->status()}, nothing staged",
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

        $payload = [
            'id' => $live->id(),
            'slug' => $live->slug(),
            'status' => $live->status(),
            'url' => $live->url(),
            ...$this->entryLiveness($live, $promotingWorkingCopy ? self::LIVENESS_PUBLISHED_WORKING_COPY : self::LIVENESS_PUBLISHED),
        ];

        if ($live->collection()->dated()) {
            $payload['date'] = $live->date()?->toIso8601String();
        }

        return $this->json($payload);
    }
}
