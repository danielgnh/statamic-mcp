<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('exposes forms through exposedHandles honoring config', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

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
    expect($probe->handles('forms'))->toEqualCanonicalizing(['contact', 'newsletter']);

    // Array = intersection with existing handles.
    config(['statamic.mcp.resources.forms' => ['contact', 'ghost']]);
    expect($probe->handles('forms'))->toBe(['contact']);

    expect(fn () => $probe->exposed('forms', 'newsletter'))
        ->toThrow("form 'newsletter' not found — available: contact");

    // Upgrade safety: a published config WITHOUT the key exposes nothing.
    config(['statamic.mcp.resources' => ['collections' => true, 'taxonomies' => true, 'globals' => true, 'asset_containers' => true, 'navigations' => true]]);
    expect($probe->handles('forms'))->toBe([]);
});
