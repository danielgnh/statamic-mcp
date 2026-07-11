<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('exposes asset containers through exposedHandles honoring config', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('docs');

    $probe = new class extends Tool
    {
        protected function execute(Request $request): Response
        {
            throw new RuntimeException('unused');
        }

        public function handles(string $type): array
        {
            return $this->exposedHandles($type);
        }
    };

    // Package default: true = all handles.
    expect($probe->handles('asset_containers'))->toEqualCanonicalizing(['images', 'docs']);

    // Array = intersection with existing handles.
    config(['statamic.mcp.resources.asset_containers' => ['images', 'ghost']]);
    expect($probe->handles('asset_containers'))->toBe(['images']);

    // Upgrade safety: a published config WITHOUT the key exposes nothing.
    config(['statamic.mcp.resources' => ['collections' => true, 'taxonomies' => true, 'globals' => true]]);
    expect($probe->handles('asset_containers'))->toBe([]);
});
