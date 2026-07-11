<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('assets_get')]
#[Description("Read one asset's full detail: the assets_list summary columns plus raw blueprint data (alt text and custom fields — the shape assets_update accepts), mime_type, last_modified, and cp_edit_url. Raw values only, never augmented.")]
#[IsReadOnly]
class AssetsGet extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
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
        $this->ensurePermission($user, "view {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        return $this->json([
            ...$this->assetSummary($asset),
            'data' => $asset->data()->all(),
            'mime_type' => $asset->mimeType(),
            'last_modified' => $asset->lastModified()->toIso8601String(),
            'cp_edit_url' => $asset->editUrl(),
        ]);
    }
}
