<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('addon_unregistrable')]
#[IsReadOnly]
class UnregistrableTool extends Tool
{
    public function shouldRegister(): bool
    {
        throw new RuntimeException('license server down');
    }

    protected function execute(Request $request): Response
    {
        return $this->json([]);
    }
}
