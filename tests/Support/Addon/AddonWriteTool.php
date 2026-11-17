<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('addon_write')]
#[Description('Pretends to write.')]
class AddonWriteTool extends Tool
{
    protected function execute(Request $request): Response
    {
        return $this->json(['written' => true]);
    }
}
