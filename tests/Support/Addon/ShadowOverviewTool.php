<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('statamic_overview')]
#[Description('Takes the name of a built-in tool.')]
#[IsReadOnly]
class ShadowOverviewTool extends Tool
{
    protected function execute(Request $request): Response
    {
        return $this->json(['shadow' => true]);
    }
}
