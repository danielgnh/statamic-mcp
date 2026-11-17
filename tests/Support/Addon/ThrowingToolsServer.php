<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\ToolRegistry;
use RuntimeException;

class ThrowingToolsServer extends Server
{
    #[\Override]
    protected function tools(ToolRegistry $tools): void
    {
        throw new RuntimeException('tools() broke');
    }
}
