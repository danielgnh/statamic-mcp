<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('exposes navigations through exposedHandles honoring config', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::nav('footer');

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

        public function exposed(string $type, string $handle): void
        {
            $this->ensureExposed($type, $handle);
        }
    };

    // Package default: true = all handles.
    expect($probe->handles('navigations'))->toEqualCanonicalizing(['main', 'footer']);

    // Array = intersection with existing handles.
    config(['statamic.mcp.resources.navigations' => ['main', 'ghost']]);
    expect($probe->handles('navigations'))->toBe(['main']);

    expect(fn () => $probe->exposed('navigations', 'footer'))
        ->toThrow("navigation 'footer' not found — available: main");

    // Upgrade safety: a published config WITHOUT the key exposes nothing.
    config(['statamic.mcp.resources' => ['collections' => true, 'taxonomies' => true, 'globals' => true, 'asset_containers' => true]]);
    expect($probe->handles('navigations'))->toBe([]);
});
