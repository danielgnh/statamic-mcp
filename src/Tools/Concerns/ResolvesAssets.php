<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\AssetContainer;

trait ResolvesAssets
{
    /**
     * Exposure check + fetch in one step. Deliberately not delegating to
     * ensureExposed() — its message singularizes 'asset_containers' to
     * 'asset_container' (underscore), whereas the human-facing wording here
     * is 'asset container' (space). Exists-but-unexposed and missing are
     * still indistinguishable by design (spec §4): the error lists only
     * exposed handles either way.
     */
    protected function resolveContainer(string $handle): AssetContainerContract
    {
        $available = $this->exposedHandles('asset_containers');

        $container = in_array($handle, $available, true) ? AssetContainer::findByHandle($handle) : null;

        if ($container === null) {
            throw new ToolException($this->notFoundMessage('asset container', $handle, $available));
        }

        return $container;
    }

    protected function resolveAsset(AssetContainerContract $container, string $path): AssetContract
    {
        $asset = $container->asset(ltrim($path, '/'));

        if (! $asset) {
            throw new ToolException(sprintf(
                "asset '%s' not found in container '%s' — use assets_list to see available paths",
                $path,
                $container->handle(),
            ));
        }

        return $asset;
    }

    /**
     * Normalized folder path or null for the container root. Forward slashes
     * only, no traversal; nested folders ('blog/2026') are fine — Statamic
     * folders are implicit, created by writing into them.
     */
    protected function normalizeFolder(?string $folder): ?string
    {
        if ($folder === null) {
            return null;
        }

        $folder = trim($folder, "/ \t");

        if ($folder === '' || $folder === '.') {
            return null;
        }

        if (str_contains($folder, '..') || str_contains($folder, '\\')) {
            throw new ToolException("folder may not contain '..' or backslashes — pass a path like 'blog/2026'");
        }

        return $folder;
    }

    /**
     * The summary row shared by assets_list, assets_get, and assets_upload
     * responses — enough to pick or reference an image without another call.
     *
     * @return array<string, mixed>
     */
    protected function assetSummary(AssetContract $asset): array
    {
        $folder = $asset->folder();
        $dimensions = $asset->dimensions();

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'basename' => $asset->basename(),
            'folder' => in_array($folder, ['.', '/', ''], true) ? null : $folder,
            'url' => $asset->url(),
            'is_image' => $asset->isImage(),
            'size' => $asset->size(),
            'dimensions' => array_filter($dimensions) === [] ? null : $dimensions,
            'alt' => $asset->data()->get('alt'),
        ];
    }
}
