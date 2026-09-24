<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Statamic\CP\LivePreview;
use Statamic\Facades\Blink;
use Statamic\Facades\URL;

#[Name('entries_preview')]
#[Description('Get a URL that renders an entry through the site\'s own templates, so you can check how your changes look without a human opening the browser. Fetch the URL to see the page. It uses Statamic\'s Live Preview, so unpublished drafts render even though the public site returns 404 for them. On revision-enabled entries with a staged working copy, the page shows the working copy, not the live entry. The URL works for anyone who has it until expires_at, an hour after the call, so share it only with people who may see the draft. Requires the collection\'s edit permission, as Live Preview does in the Control Panel. Entries of a collection without a route have no page to preview. target picks a preview target by label and defaults to the collection\'s first; targets lists them all. Each call creates a new preview token and changes no content.')]
#[IsDestructive(false)]
class EntriesPreview extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'site' => $schema->string()->description("Selector only: must match the entry's own site, or be omitted."),
            'target' => $schema->string()->description("Preview target label, as listed in targets. Defaults to the collection's first preview target."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'site' => 'nullable|string',
            'target' => 'nullable|string',
        ]);

        $user = $this->user($request);

        $entry = $this->findExposedEntry($validated['id'], $user, data_get($validated, 'site'));

        $collectionHandle = $entry->collection()->handle();

        // CP parity (PreviewController@edit, 6.x): live preview authorizes
        // 'update', which EntryPolicy grants on the edit permission.
        $this->ensurePermission($user, "edit {$collectionHandle} entries");

        if (blank($entry->route())) {
            throw new ToolException(sprintf(
                "entry '%s' cannot be previewed — collection '%s' has no route for site '%s', so its entries have no page",
                $entry->id(),
                $collectionHandle,
                $entry->locale(),
            ));
        }

        // The working copy is what the CP's edit form and entries_publish both
        // start from. Without revisions fromWorkingCopy() returns the instance
        // it is called on, so call it on a clone: the supplement must never
        // reach the Stache instance.
        $item = (clone $entry)->fromWorkingCopy();
        $item->setSupplement('live_preview', true);

        // uri() is memoized per entry id with the live URI, but the frontend
        // serves the preview under the item's own URI, which a staged slug
        // change moves. Forget it on both sides so the live entry never
        // reports the staged URI afterwards.
        Blink::store('entry-uris')->forget($item->id());
        $targets = $item->previewTargets();
        Blink::store('entry-uris')->forget($item->id());

        $label = data_get($validated, 'target');
        $target = filled($label) ? $targets->firstWhere('label', $label) : $targets->first();

        if (blank($target)) {
            throw new ToolException($this->notFoundMessage('preview target', $label, $targets->pluck('label')->all()));
        }

        $token = app(LivePreview::class)->tokenize(null, $item);

        return $this->json([
            'id' => $entry->id(),
            'url' => $this->previewUrl($target['url'], $token->token()),
            'expires_at' => $token->expiry()->toIso8601String(),
            'target' => $target['label'],
            'targets' => $targets->pluck('label')->all(),
        ]);
    }

    /**
     * The shape the CP's Live Preview builds (PreviewController::getPreviewUrl,
     * 6.x), so a frontend that handles CP previews handles these too.
     */
    private function previewUrl(string $url, string $token): string
    {
        $url = URL::makeAbsolute($url);

        return $url.(str_contains($url, '?') ? '&' : '?').Arr::query([
            'live-preview' => Str::random(),
            'token' => $token,
        ]);
    }
}
