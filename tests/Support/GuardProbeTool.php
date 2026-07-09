<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Minimal concrete Tool exercising the base guards in tests — never registered
 * on the Server.
 */
class GuardProbeTool extends Tool
{
    protected function execute(Request $request): Response
    {
        match ($request->get('guard')) {
            'writes' => $this->ensureWritesEnabled(),
            'deletes' => $this->ensureDeletesEnabled(),
            default => null,
        };

        return $this->json(['ok' => true]);
    }
}
