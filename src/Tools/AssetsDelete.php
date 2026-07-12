<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('assets_delete')]
#[Description("Permanently delete an asset — the file AND its metadata. References to it in entry fields are removed by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false). This cannot be undone. Only available when deletes are enabled in config/statamic/mcp.php.")]
#[IsDestructive]
class AssetsDelete extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
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

        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'path' => 'required|string',
            ],
            [
                'container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.',
                'path.required' => "Pass an asset path relative to the container root, e.g. 'blog/hero.jpg'.",
            ],
        );

        $handle = $validated['container'];
        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "delete {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        $id = $asset->id();
        $path = $asset->path();

        // No TermsDelete-style syncOriginal needed: UpdateAssetReferences'
        // delete handler keys off getOriginal('path'), and Asset::getOriginal()
        // self-heals by syncing from current state when originals are empty.
        // delete() returns false when an AssetDeleting listener cancels
        // (approval addons do this) — never report success for it.
        if (! $asset->delete()) {
            throw new ToolException('the delete was cancelled by a listener — the asset was not deleted');
        }

        // Outcome statement only — deliberately NO cp_edit_url: the deleted
        // asset's CP page would 404 (amended spec exception, same as the
        // other delete tools).
        return $this->json([
            'deleted' => true,
            'id' => $id,
            'container' => $handle,
            'path' => $path,
            'result' => 'asset permanently deleted — file and metadata removed; this cannot be undone',
            'note' => "references to this asset in entry fields are removed by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false) — an immediate re-read may still show the path in entry fields; do not rewrite references manually",
        ]);
    }
}
