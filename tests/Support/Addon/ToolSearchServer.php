<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tools\ToolSearch;

class ToolSearchServer extends Server
{
    protected array $tools = [
        AddonReadTool::class,
        ToolSearch::class => [AddonWriteTool::class],
    ];
}
