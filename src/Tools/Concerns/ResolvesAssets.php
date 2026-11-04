<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\AssetContainer;

trait ResolvesAssets
{
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
        $asset = $this->findAsset($container, $path);

        if (! $asset) {
            throw new ToolException(sprintf(
                "asset '%s' not found in container '%s' — use assets_list to see available paths",
                $path,
                $container->handle(),
            ));
        }

        return $asset;
    }

    protected function findAsset(AssetContainerContract $container, string $path): ?AssetContract
    {
        return $container->asset(ltrim($path, '/'));
    }

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
     * @return array<string, mixed>
     */
    protected function assetSummary(AssetContract $asset): array
    {
        $dimensions = $asset->dimensions();

        return [
            'path' => $asset->path(),
            'url' => $asset->url(),
            'is_image' => $asset->isImage(),
            'size' => $asset->size(),
            'dimensions' => array_filter($dimensions) === [] ? null : $dimensions,
            'alt' => $asset->data()->get('alt'),
        ];
    }
}
